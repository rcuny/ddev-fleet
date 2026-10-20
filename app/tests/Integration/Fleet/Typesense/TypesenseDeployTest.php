<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Typesense;

use App\Domain\Instance\InstanceState;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Typesense\TypesenseEnvFileStep;
use App\Fleet\Typesense\TypesenseEnvironment;
use App\Fleet\Typesense\TypesenseKeys;
use App\Fleet\Typesense\TypesenseSearchKeyStep;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

final class TypesenseDeployTest extends InstanceJobTestCase
{
    private TypesenseKeys $keys;
    private TypesenseEnvironment $environment;
    private InstanceHostnames $hostnames;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = new TypesenseKeys($this->secretStore);
        $this->hostnames = new InstanceHostnames(new \App\Domain\Host\HostSettingsRepository($this->em));
        $this->environment = new TypesenseEnvironment($this->keys, new NamedPortRepository($this->em), $this->hostnames, $this->masker);
    }

    private function enable(bool $on = true): void
    {
        $this->project->changeTypesense($on);
        new ProjectRepository($this->em)->save($this->project);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function searchStep(array $responses): TypesenseSearchKeyStep
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new TypesenseSearchKeyStep($this->keys, $this->environment, $this->hostnames, $client, 2, 0);
    }

    private function deploy(TypesenseSearchKeyStep $search): \App\Domain\Job\Job
    {
        $this->steps->append(new TypesenseEnvFileStep($this->keys, $this->masker));
        $this->steps->append($search);
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $log = $this->logFor($job), false);
        $log->finish();

        return $job;
    }

    private function dir(): string
    {
        return $this->paths->instanceDir('acme--main');
    }

    private function emptyKeys(): MockResponse
    {
        return new MockResponse('{"keys":[]}');
    }

    public function testKeysAreGeneratedOnceStoredSealedAndReused(): void
    {
        $this->enable();

        $first = $this->keys->ensure($this->project);
        $second = $this->keys->ensure($this->project);

        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/D', $first->admin);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/D', $first->search);
        self::assertNotSame($first->admin, $first->search);
        self::assertEquals($first, $second);
        self::assertSame($first->admin, $this->secretStore->getManaged('acme', 'TYPESENSE_API_KEY'));
        $stored = (string) $this->em->getConnection()->fetchOne("SELECT ciphertext FROM secret WHERE name = 'TYPESENSE_API_KEY'");
        self::assertStringNotContainsString($first->admin, $stored);
    }

    public function testAnOperatorValueIsNotClobbered(): void
    {
        $this->secretStore->putManaged('acme', 'TYPESENSE_API_KEY', 'operator-set-key', 'admin');

        $keys = $this->keys->ensure($this->project);

        self::assertSame('operator-set-key', $keys->admin);
        self::assertSame($keys->search, $this->secretStore->getManaged('acme', 'FLEET_TYPESENSE_SEARCH_KEY'));
    }

    public function testAFlaggedProjectGetsTheEnvFileTheWebEnvironmentAndTheSearchKey(): void
    {
        $this->enable();

        $job = $this->deploy($this->searchStep([$this->emptyKeys(), new MockResponse('{}')]));

        $keys = $this->keys->ensure($this->project);
        $dir = $this->dir();
        self::assertSame('TYPESENSE_API_KEY='.$keys->admin."\n", file_get_contents($dir.'/.ddev/.env'));
        self::assertSame(0o640, fileperms($dir.'/.ddev/.env') & 0o777);
        self::assertStringContainsString("/.ddev/.env\n", (string) file_get_contents($dir.'/.git/info/exclude'));

        $config = Yaml::parseFile($dir.'/.ddev/config.fleet.yaml');
        self::assertContains('FLEET_TYPESENSE_HOST=acme--main.fleet.example.com', $config['web_environment']);
        self::assertContains('FLEET_TYPESENSE_PORT=9108', $config['web_environment']);
        self::assertContains('TYPESENSE_API_KEY='.$keys->admin, $config['web_environment']);
        self::assertContains('FLEET_TYPESENSE_SEARCH_KEY='.$keys->search, $config['web_environment']);

        self::assertCount(2, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('http://127.0.0.1:8108/keys', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[1]['method']);
        $headers = implode("\n", $this->requests[1]['options']['normalized_headers']['x-typesense-api-key'] ?? []);
        self::assertStringContainsString($keys->admin, $headers);
        self::assertStringContainsString('acme--main.fleet.example.com', implode("\n", $this->requests[1]['options']['normalized_headers']['host'] ?? []));
        self::assertSame(
            ['value' => $keys->search, 'description' => 'fleet-search-only', 'actions' => ['documents:search'], 'collections' => ['*']],
            json_decode((string) $this->requests[1]['options']['body'], true),
        );

        $log = $this->logText($job);
        self::assertStringContainsString('typesense: search-only key registered', $log);
        self::assertStringNotContainsString($keys->admin, $log);
        self::assertStringNotContainsString($keys->search, $log);
        self::assertSame('[masked]', $this->masker->mask($keys->admin));
        self::assertSame([], array_filter($this->runner->calls(), static fn ($c): bool => str_contains(implode(' ', $c->argv), 'fleet-search-only')));
    }

    public function testAnExistingSearchKeyIsNotCreatedAgain(): void
    {
        $this->enable();

        $job = $this->deploy($this->searchStep([new MockResponse('{"keys":[{"id":1,"description":"fleet-search-only"}]}')]));

        self::assertCount(1, $this->requests);
        self::assertStringContainsString('already registered', $this->logText($job));
    }

    public function testAnUnflaggedProjectGetsNothing(): void
    {
        $job = $this->deploy($this->searchStep([]));

        self::assertFileDoesNotExist($this->dir().'/.ddev/.env');
        self::assertStringNotContainsString('TYPESENSE', (string) file_get_contents($this->dir().'/.ddev/config.fleet.yaml'));
        self::assertSame([], $this->requests);
        self::assertFalse($this->secretStore->hasManaged('acme', 'TYPESENSE_API_KEY'));
        self::assertStringNotContainsString('typesense', $this->logText($job));
    }

    public function testAnApiFailureIsAWarningAndTheDeploySucceeds(): void
    {
        $this->enable();

        $job = $this->deploy($this->searchStep([new MockResponse('nope', ['http_code' => 503]), new MockResponse('nope', ['http_code' => 503])]));

        $keys = $this->keys->ensure($this->project);
        $log = $this->logText($job);
        self::assertStringContainsString('WARNING: typesense: the search-only key was not registered', $log);
        self::assertStringNotContainsString($keys->admin, $log);
        self::assertCount(2, $this->requests);
        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
    }

    public function testASecondDeployReusesTheKeysAndRewritesTheSameValues(): void
    {
        $this->enable();
        $before = $this->keys->ensure($this->project);

        $this->deploy($this->searchStep([$this->emptyKeys(), new MockResponse('{}')]));

        self::assertEquals($before, $this->keys->ensure($this->project));
        self::assertSame('TYPESENSE_API_KEY='.$before->admin."\n", file_get_contents($this->dir().'/.ddev/.env'));
    }

    public function testTheEnvFileKeepsTheOtherVariables(): void
    {
        $this->enable();
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        mkdir($dir.'/.ddev');
        file_put_contents($dir.'/.ddev/.env', "A=1\nTYPESENSE_API_KEY=old\n");
        $context = new DeployContext($instance, $this->project, $dir, [], $this->logFor($this->job('deploy', $instance)), $this->secretRenderer);

        new TypesenseEnvFileStep($this->keys, $this->masker)->run($context);

        self::assertSame("A=1\nTYPESENSE_API_KEY=".$this->keys->ensure($this->project)->admin."\n", file_get_contents($dir.'/.ddev/.env'));
        self::assertContains('/.ddev/.env', $context->excluded());
    }
}
