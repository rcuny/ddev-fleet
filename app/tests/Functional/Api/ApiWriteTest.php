<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\IssuedToken;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FLE-277: the write endpoints, and SR3, the authorisation matrix: every write endpoint x scope x project. Two projects
 * (`acme`, `demo`), each with an instance `i1` and a protected `main`.
 */
final class ApiWriteTest extends AdminTestCase
{
    private const array ENDPOINTS = [
        // name => [method, uri template, body, scopes needed]
        'deploy' => ['POST', '/api/v1/instances', '{"project":"%p"}', [ApiScope::Deploy]],
        'redeploy' => ['POST', '/api/v1/instances/%p--i1/redeploy', '', [ApiScope::Deploy]],
        'start' => ['POST', '/api/v1/instances/%p--i1/start', '', [ApiScope::Lifecycle]],
        'stop' => ['POST', '/api/v1/instances/%p--i1/stop', '', [ApiScope::Lifecycle]],
        'destroy' => ['DELETE', '/api/v1/instances/%p--i1', '', [ApiScope::Lifecycle, ApiScope::LifecycleDestroy]],
        'batch' => ['POST', '/api/v1/instances:destroy-batch', '{"ids":["%p--i1"]}', [ApiScope::Lifecycle, ApiScope::LifecycleDestroy]],
        'retry' => ['POST', '/api/v1/jobs/%j/retry', '', [ApiScope::Lifecycle]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('develop', 'full');
            new ProjectRepository($em)->save($project);
            new TemplateRepository($em)->save(new Template($project, 'full'));
            $instances = [];
            foreach (['i1', 'main'] as $label) {
                $instance = new Instance($project, $label, 'full', 'develop', 'admin');
                $instance->changeState(InstanceState::Stopped);
                $instances[] = $instance;
            }
            new InstanceRepository($em)->createMany($instances);
        }
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     */
    private function issue(array $scopes, array $projects = ['acme']): IssuedToken
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);
        // `lifecycle:destroy` is a flag on top of `lifecycle` and cannot be issued alone.
        if (\in_array(ApiScope::LifecycleDestroy, $scopes, true) && !\in_array(ApiScope::Lifecycle, $scopes, true)) {
            $scopes = array_values(array_filter($scopes, static fn (ApiScope $s): bool => ApiScope::LifecycleDestroy !== $s));
        }

        return $issuer->issue('orchestrator', 'test', $scopes, $projects, [], [], new \DateTimeImmutable('+30 days'), 'cli');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, IssuedToken $token, string $body = '', array $headers = []): array
    {
        $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none', 'CONTENT_TYPE' => 'application/json'] + $headers, content: $body);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** A failed job of $project, for the retry endpoint. */
    private function failedJob(string $project, string $type = 'start'): Job
    {
        $job = new Job($type, $project.'--i1', $project, JobTrigger::Cli, 'admin', []);
        $job->markRunning(new \DateTimeImmutable());
        $job->finish(JobStatus::Failed, 1, 'boom', new \DateTimeImmutable());
        new JobRepository($this->em())->add($job);

        return $job;
    }

    private function expand(string $text, string $project, Job $job): string
    {
        return str_replace(['%p', '%j'], [$project, (string) $job->id()], $text);
    }

    /**
     * SR3: for every endpoint, a token with exactly the right scopes on the right project is accepted (202, or 207 for
     * the batch), and every deviation is refused: a missing scope, a wrong project, a read-only token.
     *
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        foreach (array_keys(self::ENDPOINTS) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('endpoints')]
    public function testAuthorisationMatrix(string $name): void
    {
        [$method, $template, $body, $needed] = self::ENDPOINTS[$name];

        $send = function (IssuedToken $token, string $project) use ($method, $template, $body): int {
            $job = $this->failedJob($project);
            $this->call($method, $this->expand($template, $project, $job), $token, $this->expand($body, $project, $job));

            return $this->client->getResponse()->getStatusCode();
        };

        // Exactly the needed scopes on the project: accepted.
        $ok = $send($this->issue($needed, ['acme']), 'acme');
        self::assertContains($ok, [202, 207], $name.' with the right scopes');

        // Every strict subset of the needed scopes: 403 scope_missing.
        foreach ($needed as $i => $_) {
            $subset = $needed;
            unset($subset[$i]);
            $other = array_values($subset);
            if ([] === $other || [ApiScope::LifecycleDestroy] === $other) {
                $other = [ApiScope::Read];
            }
            self::assertSame(403, $send($this->issue($other, ['acme']), 'acme'), $name.' missing a scope');
        }

        // The token holds every other write scope but not the needed ones.
        $others = array_values(array_filter([ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy], static fn (ApiScope $s): bool => !\in_array($s, $needed, true)));
        if ([] !== $others) {
            self::assertSame(403, $send($this->issue($others, ['acme']), 'acme'), $name.' with only other scopes');
        }

        // Right scopes, wrong project: 403 (the batch reports the refusal per id, still 207, nothing accepted).
        $status = $send($this->issue($needed, ['acme']), 'demo');
        if ('batch' === $name) {
            self::assertSame(207, $status);
            self::assertFalse($this->decoded()['results'][0]['accepted']);
            self::assertSame('project_not_allowed', $this->decoded()['results'][0]['code']);
        } else {
            self::assertSame(403, $status, $name.' on another project');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function testReadOnlyTokenCannotWriteAnywhere(): void
    {
        $token = $this->issue([ApiScope::Read], ['*']);
        $job = $this->failedJob('acme');
        foreach (self::ENDPOINTS as $name => [$method, $uri, $body]) {
            $this->call($method, $this->expand($uri, 'acme', $job), $token, $this->expand($body, 'acme', $job));
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $name);
        }
    }

    public function testDeployReturns202WithTheJobAndLocation(): void
    {
        $body = $this->call('POST', '/api/v1/instances', $this->issue([ApiScope::Deploy]), '{"project":"acme","branch":"feature/x","label":"feature-x"}');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('acme--feature-x', $this->em()->getRepository(Job::class)->find($body['job']['id'])?->instanceId());
        self::assertSame('/api/v1/jobs/'.$body['job']['id'], $body['job']['url']);
        self::assertSame($body['job']['url'], $this->client->getResponse()->headers->get('Location'));
    }

    public function testDeployValidationFailureIsA422WithTheField(): void
    {
        $body = $this->call('POST', '/api/v1/instances', $this->issue([ApiScope::Deploy]), '{"project":"acme","template":"nope"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $body['code']);
        self::assertArrayHasKey('template', $body['errors']);
    }

    public function testDeployNeedsAProject(): void
    {
        $this->call('POST', '/api/v1/instances', $this->issue([ApiScope::Deploy]), '{}');
        self::assertResponseStatusCodeSame(422);

        $this->call('POST', '/api/v1/instances', $this->issue([ApiScope::Deploy]), '[1]');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAcceptedWritesAreAudited(): void
    {
        $this->call('POST', '/api/v1/instances/acme--i1/start', $this->issue([ApiScope::Lifecycle]));

        $entries = self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_WRITE);
        self::assertCount(1, $entries);
        self::assertStringContainsString('start acme--i1 job=', $entries[0]->subject());
    }

    public function testMainInstanceIsProtectedFromDestroy(): void
    {
        $token = $this->issue([ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);

        $body = $this->call('DELETE', '/api/v1/instances/acme--main', $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('protected_instance', $body['code']);
    }

    public function testIfMatchGuardsDestroy(): void
    {
        $token = $this->issue([ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);
        $created = new InstanceRepository($this->em())->find('acme--i1')?->createdAt()->format(\DATE_ATOM);

        $body = $this->call('DELETE', '/api/v1/instances/acme--i1', $token, '', ['HTTP_IF_MATCH' => '"2001-01-01T00:00:00+00:00"']);
        self::assertResponseStatusCodeSame(412);
        self::assertSame('stale_if_match', $body['code']);

        $this->call('DELETE', '/api/v1/instances/acme--i1', $token, '', ['HTTP_IF_MATCH' => '"'.$created.'"']);
        self::assertResponseStatusCodeSame(202);
    }

    public function testBatchReportsEachIdSeparately(): void
    {
        $token = $this->issue([ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);

        $body = $this->call('POST', '/api/v1/instances:destroy-batch', $token, json_encode(['ids' => ['acme--i1', 'acme--main', 'acme--ghost', 'demo--i1'], 'if_match' => []], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(207);
        $by = array_column($body['results'], null, 'id');
        self::assertTrue($by['acme--i1']['accepted']);
        self::assertSame('protected_instance', $by['acme--main']['code']);
        self::assertSame('not_found', $by['acme--ghost']['code']);
        self::assertSame('project_not_allowed', $by['demo--i1']['code']);
    }

    public function testBatchRejectsABadIdList(): void
    {
        $this->call('POST', '/api/v1/instances:destroy-batch', $this->issue([ApiScope::Lifecycle, ApiScope::LifecycleDestroy]), '{"ids":[]}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testBusyInstanceAnswers409(): void
    {
        $token = $this->issue([ApiScope::Lifecycle]);
        new JobRepository($this->em())->add(new Job('stop', 'acme--i1', 'acme', JobTrigger::Cli, 'admin', []));

        $body = $this->call('POST', '/api/v1/instances/acme--i1/start', $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('instance_busy', $body['code']);
    }

    public function testRetryCreatesANewJob(): void
    {
        $failed = $this->failedJob('acme');

        $body = $this->call('POST', '/api/v1/jobs/'.$failed->id().'/retry', $this->issue([ApiScope::Lifecycle]));

        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($failed->id(), $body['job']['id']);
    }

    public function testRetryOfADeployNeedsTheDeployScope(): void
    {
        $failed = $this->failedJob('acme', 'deploy');

        $this->call('POST', '/api/v1/jobs/'.$failed->id().'/retry', $this->issue([ApiScope::Lifecycle]));
        self::assertResponseStatusCodeSame(403);

        $this->call('POST', '/api/v1/jobs/'.$failed->id().'/retry', $this->issue([ApiScope::Deploy]));
        self::assertResponseStatusCodeSame(202);
    }

    public function testUnknownInstanceIsA404ForAnAuthorisedToken(): void
    {
        $this->call('POST', '/api/v1/instances/acme--ghost/start', $this->issue([ApiScope::Lifecycle]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testThereIsNoDispatchEndpoint(): void
    {
        $this->call('POST', '/api/v1/dispatches', $this->issue([ApiScope::Deploy]), '{"project":"acme"}');
        self::assertResponseStatusCodeSame(404);
    }
}
