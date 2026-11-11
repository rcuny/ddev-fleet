<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\InstanceIsolation;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class InstanceIsolationTest extends KernelTestCase
{
    use FleetDatabase;

    private TemplateRepository $templates;
    private RegisteredSecretsMasker $masker;
    private InstanceIsolation $isolation;
    private Project $project;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->templates = new TemplateRepository($em);
        $this->masker = new RegisteredSecretsMasker();
        $this->isolation = new InstanceIsolation($this->templates, $this->masker, new RecordingCommandRunner(), 'test-secret');
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $em->persist($this->project);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function instance(string $template, bool $database, bool $agent, string $label = 'main'): Instance
    {
        $stored = new Template($this->project, $template);
        $stored->changeIsolation($database, $agent);
        $this->templates->save($stored);

        return new Instance($this->project, $label, $template, 'develop', 'admin');
    }

    public function testMailpitGetsAPerInstancePasswordThatIsMasked(): void
    {
        $a = $this->isolation->mailpitCredentials('acme--a');
        $b = $this->isolation->mailpitCredentials('acme--b');

        self::assertSame('fleet', $a['user']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $a['password']);
        self::assertNotSame($a['password'], $b['password']);
        self::assertSame($a, $this->isolation->mailpitCredentials('acme--a'));
        self::assertNotSame($a['password'], new InstanceIsolation($this->templates, $this->masker, new RecordingCommandRunner(), 'other-secret')->mailpitCredentials('acme--a')['password']);

        $instance = $this->instance('full', true, false);
        $variables = $this->isolation->variables($instance, $this->project);
        $own = $this->isolation->mailpitCredentials($instance->id());
        self::assertContains('MP_UI_AUTH=fleet:'.$own['password'], $variables);
        self::assertSame('[masked]', $this->masker->mask($own['password']));
    }

    public function testATemplateWithADatabaseSaysSoAndPoisonsNothing(): void
    {
        $variables = $this->isolation->variables($this->instance('full', true, false), $this->project);

        self::assertContains('FLEET_HAS_DB=1', $variables);
        self::assertNotContains('FLEET_AGENT=1', $variables);
        self::assertSame([], array_filter($variables, static fn (string $v): bool => str_starts_with($v, 'DB_HOST=')));
    }

    public function testADatabaseLessAgentTemplateGetsAPoisonedHostAndTheAgentFlag(): void
    {
        $variables = $this->isolation->variables($this->instance('agent', false, true), $this->project);

        self::assertContains('FLEET_HAS_DB=0', $variables);
        self::assertContains('FLEET_AGENT=1', $variables);
        foreach (['DB_HOST', 'DATABASE_HOST', 'MYSQL_HOST'] as $name) {
            self::assertContains($name.'=fleet-no-database.invalid', $variables);
        }
    }

    private function supported(RecordingCommandRunner $runner, string $docker = '27.3.1', string $compose = '2.29.7'): RecordingCommandRunner
    {
        $ok = static fn (string $out = ''): CommandResult => new CommandResult(0, $out);

        return $runner
            ->expect(['docker', 'version'], $ok($docker."\n"))
            ->expect(['docker', 'compose', 'version'], $ok($compose."\n"))
            ->expect(['docker', 'inspect'], $ok("ddev/ddev-traefik-router:v1.24\n"))
            ->expect(['docker', 'run'], $ok());
    }

    private function checkout(string $compose = ''): string
    {
        $dir = $this->tempHome.'/checkout';
        mkdir($dir.'/.ddev', 0o750, true);
        if ('' !== $compose) {
            file_put_contents($dir.'/.ddev/docker-compose.typesense.yaml', $compose);
        }

        return $dir;
    }

    /** @var list<string> */
    private array $warnings = [];

    private function log(): \Closure
    {
        $this->warnings = [];

        return function (string $line): void {
            $this->warnings[] = $line;
        };
    }

    private function with(RecordingCommandRunner $runner): InstanceIsolation
    {
        return new InstanceIsolation($this->templates, $this->masker, $runner, 'test-secret');
    }

    public function testTheComposeOverrideMountsTheTraefikTreeReadOnly(): void
    {
        $dir = $this->checkout();

        self::assertSame('.ddev/docker-compose.fleet-isolation.yaml', $this->with($this->supported(new RecordingCommandRunner()))->write($dir, $this->project, $this->log()));

        $override = Yaml::parseFile($dir.'/.ddev/docker-compose.fleet-isolation.yaml');
        self::assertSame(
            [['type' => 'volume', 'source' => 'ddev-global-cache', 'target' => '/mnt/ddev-global-cache/traefik', 'read_only' => true, 'volume' => ['subpath' => 'traefik']]],
            $override['services']['web']['volumes'],
        );
        self::assertSame(0o640, fileperms($dir.'/.ddev/docker-compose.fleet-isolation.yaml') & 0o777);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oldVersions(): iterable
    {
        yield 'old docker' => ['25.0.5', '2.29.7'];
        yield 'old compose' => ['27.3.1', '2.20.0'];
    }

    #[DataProvider('oldVersions')]
    public function testAnOldDockerOrComposeSkipsTheMountWithAWarning(string $docker, string $compose): void
    {
        $dir = $this->checkout();
        $log = $this->log();

        self::assertNull($this->with($this->supported(new RecordingCommandRunner(), $docker, $compose))->write($dir, $this->project, $log));

        self::assertFileDoesNotExist($dir.'/.ddev/docker-compose.fleet-isolation.yaml');
        self::assertStringContainsString('WARNING: isolation', implode("\n", $this->warnings));
    }

    public function testAMissingTraefikSubpathSkipsTheMount(): void
    {
        $dir = $this->checkout();
        $log = $this->log();
        $runner = new RecordingCommandRunner();
        $runner
            ->expect(['docker', 'version'], new CommandResult(0, "27.3.1\n"))
            ->expect(['docker', 'compose', 'version'], new CommandResult(0, "2.29.7\n"))
            ->expect(['docker', 'inspect'], new CommandResult(0, "ddev/ddev-traefik-router:v1.24\n"))
            ->expect(['docker', 'run'], new CommandResult(1));

        self::assertNull($this->with($runner)->write($dir, $this->project, $log));

        self::assertStringContainsString('traefik/ does not exist', implode("\n", $this->warnings));
    }

    public function testARouterThatNeverStartedSkipsTheMount(): void
    {
        $dir = $this->checkout();
        $runner = new RecordingCommandRunner();
        $runner
            ->expect(['docker', 'version'], new CommandResult(0, "27.3.1\n"))
            ->expect(['docker', 'compose', 'version'], new CommandResult(0, "2.29.7\n"))
            ->expect(['docker', 'inspect'], new CommandResult(1));

        self::assertNull($this->with($runner)->write($dir, $this->project, $this->log()));
    }

    public function testTheTypesenseDashboardIsOffUnlessTheProjectSwitchedItOn(): void
    {
        $compose = "services:\n  typesense:\n    image: typesense/typesense\n  typesense-dashboard:\n    image: x\n";
        $this->project->changeTypesense(true);

        $dir = $this->checkout($compose);
        $this->with($this->supported(new RecordingCommandRunner()))->write($dir, $this->project, $this->log());
        $override = Yaml::parseFile($dir.'/.ddev/docker-compose.fleet-isolation.yaml');
        self::assertSame(['profiles' => ['dashboard']], $override['services']['typesense-dashboard']);

        $this->project->changeTypesenseDashboard(true);
        $on = $this->with($this->supported(new RecordingCommandRunner()))->write($dir, $this->project, $this->log());
        self::assertArrayNotHasKey('typesense-dashboard', Yaml::parseFile($dir.'/'.$on)['services']);
    }

    public function testNoDashboardServiceInTheProjectsComposeMeansNoOverrideForIt(): void
    {
        $this->project->changeTypesense(true);
        $dir = $this->checkout("services:\n  typesense:\n    image: typesense/typesense\n");

        $this->with($this->supported(new RecordingCommandRunner()))->write($dir, $this->project, $this->log());

        self::assertArrayNotHasKey('typesense-dashboard', Yaml::parseFile($dir.'/.ddev/docker-compose.fleet-isolation.yaml')['services']);
    }
}
