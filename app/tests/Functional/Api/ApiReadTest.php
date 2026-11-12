<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\IssuedToken;
use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiToken;
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
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Security\Api\ApiAccessDeniedHandler;
use App\Security\Api\ApiTokenUser;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\RecordingHub;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * FLE-276: the read endpoints (design section 6.1 row by row) end to end through the api firewall, the scope check and
 * the project filter. Two projects, `acme` and `demo`, so a token limited to one proves the other stays hidden.
 */
final class ApiReadTest extends AdminTestCase
{
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
            $instance = new Instance($project, 'i1', 'full', 'develop', 'admin');
            $instance->changeState(InstanceState::Running);
            new InstanceRepository($em)->createMany([$instance]);
        }
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     */
    private function issue(array $scopes = [ApiScope::Read], array $projects = ['*']): IssuedToken
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);

        return $issuer->issue('orchestrator', 'test', $scopes, $projects, [], [], new \DateTimeImmutable('+30 days'), 'cli');
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, ?IssuedToken $token = null): array
    {
        $token ??= $this->issue();
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function job(string $project, JobStatus $status = JobStatus::Succeeded, ?string $instance = 'i1'): Job
    {
        $job = new Job('start', null === $instance ? null : $project.'--'.$instance, $project, JobTrigger::Cli, 'admin', ['secret' => 'hunter2']);
        $job->markRunning(new \DateTimeImmutable());
        if ($status->isFinal()) {
            $job->finish($status, 0, null, new \DateTimeImmutable());
        }
        new JobRepository($this->em())->add($job);

        return $job;
    }

    private function writeLog(Job $job, string $text): void
    {
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $path = $paths->jobLog((string) $job->id(), $job->instanceId());
        @mkdir(\dirname($path), 0o750, true);
        file_put_contents($path, $text);
    }

    public function testHostReportsNameVersionAndCounts(): void
    {
        $body = $this->get('/api/v1/host');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('fleet.example.com', $body['host']);
        self::assertSame(2, $body['instances']['total']);
        self::assertSame(2, $body['projects']);
        self::assertArrayHasKey('version', $body);
    }

    public function testCapacityIsTheSchemaOneDocumentWithAVerdictOnDemand(): void
    {
        $body = $this->get('/api/v1/host/capacity?project=acme&template=full');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $body['schema']);
        self::assertSame(['acme', 'demo'], array_column($body['projects'], 'name'));
        self::assertSame('acme', $body['verdict']['project']);
        self::assertIsBool($body['verdict']['ok']);
        self::assertIsArray($body['verdict']['reasons']);
    }

    public function testCapacityWithoutAProjectHasNoVerdict(): void
    {
        self::assertArrayNotHasKey('verdict', $this->get('/api/v1/host/capacity'));
    }

    public function testCapacityVerdictForAnUnknownProjectIs404(): void
    {
        $body = $this->get('/api/v1/host/capacity?project=ghost');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['code']);
    }

    /**
     * FLE-291: a project-limited token gets the verdict and figures of its own project, nothing else.
     */
    public function testCapacityOfOwnProjectIsScopedForAProjectLimitedToken(): void
    {
        $body = $this->get('/api/v1/host/capacity?project=acme&template=full', $this->issue([ApiScope::Read], ['acme']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['schema', 'scope', 'project', 'verdict', 'instances'], array_keys($body));
        self::assertSame('project', $body['scope']);
        self::assertSame('acme', $body['verdict']['project']);
        self::assertIsBool($body['verdict']['ok']);
        self::assertIsArray($body['verdict']['reasons']);
        self::assertSame(['project_count', 'remaining'], array_keys($body['instances']));
        self::assertIsInt($body['instances']['remaining']);
        self::assertArrayNotHasKey('projects', $body);
        self::assertArrayNotHasKey('instances', $body['verdict']);
        self::assertStringNotContainsString('demo', (string) json_encode($body));
    }

    public function testCapacityOfAnotherProjectIs403ForAProjectLimitedToken(): void
    {
        $body = $this->get('/api/v1/host/capacity?project=demo', $this->issue([ApiScope::Read], ['acme']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('project_not_allowed', $body['code']);
        self::assertStringNotContainsString('"projects"', (string) json_encode($body));
    }

    public function testCapacityOfAnUnknownProjectIsTheSame403AsAnotherProjectsName(): void
    {
        $token = $this->issue([ApiScope::Read], ['acme']);
        $other = $this->get('/api/v1/host/capacity?project=demo', $token);
        self::assertResponseStatusCodeSame(403);
        $ghost = $this->get('/api/v1/host/capacity?project=ghost', $token);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($other['code'], $ghost['code']);
        unset($other['request_id'], $ghost['request_id']);
        self::assertSame(
            str_replace('demo', '<p>', (string) json_encode($other)),
            str_replace('ghost', '<p>', (string) json_encode($ghost)),
        );
    }

    /**
     * FLE-276 review (3): host-wide data is for a token that sees every project.
     */
    #[DataProvider('hostWidePaths')]
    public function testHostWideDataIs403ForAProjectLimitedToken(string $path): void
    {
        $body = $this->get($path, $this->issue([ApiScope::Read], ['demo']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('host_wide_not_allowed', $body['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostWidePaths(): iterable
    {
        yield 'host' => ['/api/v1/host'];
        yield 'capacity' => ['/api/v1/host/capacity'];
        yield 'upgrade' => ['/api/v1/upgrade'];
    }

    public function testAnUnrestrictedTokenMeansAllProjectsWhateverItsScopes(): void
    {
        $user = ApiTokenUser::fromToken($this->issue([ApiScope::Read, ApiScope::Deploy], ['demo'])->token);
        self::assertFalse($user->unrestricted());

        $wild = $this->issue([ApiScope::Read], ['*'])->token;
        self::assertTrue(ApiTokenUser::fromToken($wild)->unrestricted());
        // Even with extra scopes stored (defence in depth behind the issuing rules).
        $this->em()->getConnection()->executeStatement('UPDATE api_token SET scopes = ? WHERE id = ?', [json_encode(['read', 'deploy']), $wild->id()]);
        $this->em()->clear();
        $reloaded = $this->em()->find(ApiToken::class, $wild->id());
        self::assertInstanceOf(ApiToken::class, $reloaded);
        self::assertTrue(ApiTokenUser::fromToken($reloaded)->unrestricted());
    }

    public function testProjectsAreFilteredByTheTokensList(): void
    {
        $all = $this->get('/api/v1/projects');
        self::assertSame(['acme', 'demo'], array_column($all['projects'], 'name'));

        $one = $this->get('/api/v1/projects', $this->issue([ApiScope::Read], ['acme']));
        self::assertSame(['acme'], array_column($one['projects'], 'name'));
    }

    public function testAProjectOutsideTheListIs403WhetherOrNotItExists(): void
    {
        $token = $this->issue([ApiScope::Read], ['acme']);

        foreach (['demo', 'ghost'] as $name) {
            $body = $this->get('/api/v1/projects/'.$name, $token);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('project_not_allowed', $body['code']);
        }
        $this->get('/api/v1/projects/acme', $token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAnUnknownProjectIs404ForAnUnrestrictedToken(): void
    {
        $this->get('/api/v1/projects/ghost');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTemplatesListAndShow(): void
    {
        $list = $this->get('/api/v1/projects/acme/templates');
        self::assertSame(['full'], array_column($list['templates'], 'name'));

        $one = $this->get('/api/v1/templates/acme/full');
        self::assertSame('full', $one['name']);
        self::assertSame('acme', $one['project']);

        $this->get('/api/v1/templates/acme/missing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInstancesListWithFiltersAndTheExtraFields(): void
    {
        $job = $this->job('acme', JobStatus::Running);

        $body = $this->get('/api/v1/instances?project=acme');

        self::assertCount(1, $body['instances']);
        $row = $body['instances'][0];
        self::assertSame('acme--i1', $row['id']);
        self::assertSame('running', $row['state']);
        self::assertSame('https://acme--i1.fleet.example.com', $row['url']);
        self::assertNotEmpty($row['created_at']);
        self::assertSame(['job_id' => $job->id()], $row['busy']);
        self::assertNull($body['next_cursor']);

        self::assertCount(2, $this->get('/api/v1/instances')['instances']);
        self::assertCount(0, $this->get('/api/v1/instances?state=stopped')['instances']);
        self::assertCount(1, $this->get('/api/v1/instances?label=i1&project=demo')['instances']);
        self::assertCount(0, $this->get('/api/v1/instances?branch=nope')['instances']);
    }

    public function testInstanceListRejectsAnUnknownStateAndHidesOtherProjects(): void
    {
        $this->get('/api/v1/instances?state=bogus');
        self::assertResponseStatusCodeSame(400);

        $body = $this->get('/api/v1/instances', $this->issue([ApiScope::Read], ['demo']));
        self::assertSame(['demo--i1'], array_column($body['instances'], 'id'));
        $this->get('/api/v1/instances?project=acme', $this->issue([ApiScope::Read], ['demo']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testInstancePaginationWalksTheCursor(): void
    {
        $first = $this->get('/api/v1/instances?limit=1');
        self::assertCount(1, $first['instances']);
        self::assertNotNull($first['next_cursor']);

        $second = $this->get('/api/v1/instances?limit=1&cursor='.$first['next_cursor']);
        self::assertSame(['demo--i1'], array_column($second['instances'], 'id'));
        self::assertNull($second['next_cursor']);
    }

    #[DataProvider('badPaging')]
    public function testBadPagingIs400(string $query, string $code): void
    {
        $body = $this->get('/api/v1/instances?'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($code, $body['code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badPaging(): iterable
    {
        yield 'zero limit' => ['limit=0', 'invalid_limit'];
        yield 'too big' => ['limit=201', 'invalid_limit'];
        yield 'text' => ['limit=ten', 'invalid_limit'];
        yield 'forged cursor' => ['cursor=abc', 'invalid_cursor'];
    }

    public function testInstanceShowListsItsLastJobs(): void
    {
        $this->job('acme');

        $body = $this->get('/api/v1/instances/acme--i1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('acme--i1', $body['id']);
        self::assertCount(1, $body['jobs']);
        self::assertSame('succeeded', $body['jobs'][0]['status']);

        $this->get('/api/v1/instances/acme--i1', $this->issue([ApiScope::Read], ['demo']));
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/v1/instances/nothing--here');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJobsListShowAndNeverExposeParams(): void
    {
        $acme = $this->job('acme');
        $demo = $this->job('demo');

        $all = $this->get('/api/v1/jobs');
        self::assertSame([$demo->id(), $acme->id()], array_column($all['jobs'], 'id'));
        self::assertStringNotContainsString('hunter2', (string) $this->client->getResponse()->getContent());

        $limited = $this->get('/api/v1/jobs', $this->issue([ApiScope::Read], ['acme']));
        self::assertSame([$acme->id()], array_column($limited['jobs'], 'id'));

        $one = $this->get('/api/v1/jobs/'.$acme->id());
        self::assertSame('succeeded', $one['status']);
        self::assertTrue($one['final']);
        self::assertSame(0, $one['exit_code']);

        $this->get('/api/v1/jobs/'.$demo->id(), $this->issue([ApiScope::Read], ['acme']));
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/v1/jobs/99999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJobsPaginateNewestFirst(): void
    {
        $a = $this->job('acme');
        $b = $this->job('acme');
        $c = $this->job('acme');

        $first = $this->get('/api/v1/jobs?limit=2');
        self::assertSame([$c->id(), $b->id()], array_column($first['jobs'], 'id'));
        $second = $this->get('/api/v1/jobs?limit=2&cursor='.$first['next_cursor']);
        self::assertSame([$a->id()], array_column($second['jobs'], 'id'));
        self::assertNull($second['next_cursor']);

        self::assertCount(0, $this->get('/api/v1/jobs?status=failed')['jobs']);
        $this->get('/api/v1/jobs?status=weird');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAJobWithoutAProjectIsForUnrestrictedTokensOnly(): void
    {
        $job = new Job('upgrade', null, null, JobTrigger::Cli, 'admin');
        new JobRepository($this->em())->add($job);

        self::assertContains($job->id(), array_column($this->get('/api/v1/jobs')['jobs'], 'id'));
        self::assertNotContains($job->id(), array_column($this->get('/api/v1/jobs', $this->issue([ApiScope::Read], ['acme']))['jobs'], 'id'));
        $this->get('/api/v1/jobs/'.$job->id(), $this->issue([ApiScope::Read], ['acme']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testJobLogTailsFromAnOffset(): void
    {
        $job = $this->job('acme', JobStatus::Running);
        $this->writeLog($job, "one\ntwo\n");
        $token = $this->issue();

        $this->client->request('GET', '/api/v1/jobs/'.$job->id().'/log', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame("one\ntwo\n", $response->getContent());
        self::assertSame('8', $response->headers->get('X-Next-Offset'));
        self::assertSame('running', $response->headers->get('X-Job-State'));

        file_put_contents((string) self::getContainer()->get(FleetPaths::class)->jobLog((string) $job->id(), $job->instanceId()), "three\n", \FILE_APPEND);
        $this->client->request('GET', '/api/v1/jobs/'.$job->id().'/log?offset=8', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        self::assertSame("three\n", $this->client->getResponse()->getContent());
        self::assertSame('14', $this->client->getResponse()->headers->get('X-Next-Offset'));
    }

    public function testJobLogRejectsABadOffset(): void
    {
        $job = $this->job('acme');

        $body = $this->get('/api/v1/jobs/'.$job->id().'/log?offset=-1');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_offset', $body['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function postStreamToken(Job $job, IssuedToken $token): array
    {
        $this->client->request('POST', '/api/v1/jobs/'.$job->id().'/stream-token', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function enableHubTokens(): void
    {
        $hub = self::getContainer()->get(RecordingHub::class);
        self::assertInstanceOf(RecordingHub::class, $hub);
        $hub->useFactory(new LcobucciFactory('test-only-stream-secret-0123456789abcdef', 'hmac.sha256', 0, '', ProtocolVersion::Legacy));
    }

    public function testStreamTokenIsSubscribeOnlyForThatJobsTopicAndExpires(): void
    {
        $this->enableHubTokens();
        $job = $this->job('acme', JobStatus::Running);

        $body = $this->postStreamToken($job, $this->issue([ApiScope::Read], ['acme']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/jobs/'.$job->id(), $body['topic']);
        self::assertStringEndsWith('/.well-known/mercure', (string) $body['hub_url']);
        self::assertStringStartsWith('http', (string) $body['hub_url']);
        $parts = explode('.', (string) $body['token']);
        self::assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($claims);
        self::assertSame(['/jobs/'.$job->id()], $claims['mercure']['subscribe']);
        self::assertArrayNotHasKey('publish', $claims['mercure']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(time() + 601, $claims['exp']);
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_entry WHERE action = ?', [AuditLog::API_WRITE]);
        self::assertStringContainsString('stream-token job='.$job->id(), (string) $audit[0]['subject']);
        self::assertStringNotContainsString((string) $body['token'], (string) $audit[0]['subject']);
    }

    public function testStreamTokenFollowsTheJobVisibilityOfTheReadScope(): void
    {
        $this->enableHubTokens();
        $other = $this->job('demo');

        $this->postStreamToken($other, $this->issue([ApiScope::Read], ['acme']));
        self::assertResponseStatusCodeSame(403);

        $this->postStreamToken($other, $this->issue([ApiScope::Deploy], ['demo']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testStreamTokenIsCappedAtTheApiTokensOwnExpiry(): void
    {
        $this->enableHubTokens();
        $job = $this->job('acme', JobStatus::Running);
        $issued = $this->issue();
        $this->em()->getConnection()->executeStatement('UPDATE api_token SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 120), $issued->token->id()]);
        $this->em()->clear();

        $body = $this->postStreamToken($job, $issued);

        self::assertResponseStatusCodeSame(200);
        $parts = explode('.', (string) $body['token']);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($claims);
        self::assertLessThanOrEqual(time() + 121, $claims['exp']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(time() + 121, strtotime((string) $body['expires_at']));
    }

    public function testStreamTokenIsRefusedForAnExpiredOrRevokedApiTokenAndAnUnknownJob(): void
    {
        $this->enableHubTokens();
        $job = $this->job('acme', JobStatus::Running);

        $expired = $this->issue();
        $this->em()->getConnection()->executeStatement('UPDATE api_token SET expires_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $expired->token->id()]);
        $this->em()->clear();
        $this->postStreamToken($job, $expired);
        self::assertResponseStatusCodeSame(401);

        $revoked = $this->issue();
        $stored = $this->em()->find($revoked->token::class, $revoked->token->id());
        self::assertNotNull($stored);
        self::getContainer()->get(ApiTokenIssuer::class)->revoke($stored, 'cli');
        $this->postStreamToken($job, $revoked);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/v1/jobs/999999/stream-token', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->issue()->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStreamTokenAnswers503WithoutAHub(): void
    {
        $job = $this->job('acme', JobStatus::Running);

        $body = $this->postStreamToken($job, $this->issue());

        self::assertResponseStatusCodeSame(503);
        self::assertSame('streaming_unavailable', $body['code']);
    }

    public function testUpgradeIsReadOnlyAndOffline(): void
    {
        $body = $this->get('/api/v1/upgrade');

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('installed', $body);
        self::assertArrayHasKey('behind', $body);
        self::assertArrayHasKey('pending_migrations', $body);
    }

    public function testWebhookLogIsFilteredAndHidesBodies(): void
    {
        $em = $this->em();
        $repo = new WebhookRepository($em);
        foreach (['acme' => 'FLE-1', 'demo' => 'FLE-2'] as $project => $subject) {
            $delivery = new WebhookDelivery(new \DateTimeImmutable(), $project, WebhookSource::Jira, 'jira:issue_updated', 'd-'.$project, WebhookDelivery::ACCEPTED, null, $subject, null, null, 'deploy');
            $repo->saveDelivery($delivery);
        }
        $repo->flush();

        $all = $this->get('/api/v1/webhooks/log');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['demo', 'acme'], array_column($all['deliveries'], 'project'));

        $limited = $this->get('/api/v1/webhooks/log', $this->issue([ApiScope::Read], ['acme']));
        self::assertSame(['acme'], array_column($limited['deliveries'], 'project'));
        self::assertArrayNotHasKey('body_hash', $limited['deliveries'][0]);

        $this->get('/api/v1/webhooks/log?project=demo', $this->issue([ApiScope::Read], ['acme']));
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/v1/webhooks/log?result=weird');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * FLE-273 review (MAJOR 2/3): ApiAuthorizer::require answers 403 end to end, and audits it.
     */
    public function testAMissingScopeIs403WithTheScopeNamedAndAudited(): void
    {
        $token = $this->issue([ApiScope::Deploy], ['acme']);

        $body = $this->get('/api/v1/instances', $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('scope_missing', $body['code']);
        self::assertStringContainsString('read', $body['detail']);
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_entry WHERE action = ?', [AuditLog::API_DENIED]);
        self::assertCount(1, $audit);
        self::assertStringContainsString('scope_missing:read', json_encode($audit, \JSON_THROW_ON_ERROR));
    }

    public function testNoUnauthorizedBodyOrAuditRowContainsTheSecret(): void
    {
        $token = $this->issue();
        $forged = substr($token->secret, 0, -1).('a' === substr($token->secret, -1) ? 'b' : 'a');

        $this->client->request('GET', '/api/v1/instances', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$forged, 'REMOTE_ADDR' => $this->clientIp]);
        self::assertResponseStatusCodeSame(401);
        $dump = (string) $this->client->getResponse()->getContent().json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_entry'), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($forged, $dump);
        self::assertStringNotContainsString($token->secret, $dump);
        self::assertStringNotContainsString(substr($token->secret, 4, 12), $dump);
    }

    public function testAnIpv4MappedClientMatchesAnIpv4AllowList(): void
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);
        $token = $issuer->issue('ip', 'test', [ApiScope::Read], ['*'], [], ['203.0.113.0/24'], new \DateTimeImmutable('+30 days'), 'cli');

        $this->client->request('GET', '/api/v1/host', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => '::ffff:203.0.113.9']);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/v1/host', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => '::ffff:198.51.100.9']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testEveryReadEndpointNeedsAToken(): void
    {
        foreach (['/api/v1/host', '/api/v1/host/capacity', '/api/v1/projects', '/api/v1/projects/acme', '/api/v1/projects/acme/templates', '/api/v1/templates/acme/full', '/api/v1/instances', '/api/v1/instances/acme--i1', '/api/v1/jobs', '/api/v1/jobs/1', '/api/v1/jobs/1/log', '/api/v1/upgrade', '/api/v1/webhooks/log'] as $uri) {
            $this->client->request('GET', $uri, server: ['REMOTE_ADDR' => $this->clientIp]);
            self::assertResponseStatusCodeSame(401, $uri);
        }
    }

    /**
     * FLE-273 item c (FLE-276 review): an access_control denial answers 403 problem+json and is audited.
     */
    public function testAnAccessControlDenialIsAuditedAnd403(): void
    {
        $handler = self::getContainer()->get(ApiAccessDeniedHandler::class);
        self::assertInstanceOf(ApiAccessDeniedHandler::class, $handler);

        $response = $handler->handle(Request::create('/api/v1/x', server: ['REMOTE_ADDR' => '192.0.2.1']), new AccessDeniedException());

        self::assertSame(403, $response->getStatusCode());
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_entry WHERE action = ?', [AuditLog::API_DENIED]);
        self::assertCount(1, $audit);
        self::assertStringContainsString('access_control:/api/v1/x', (string) $audit[0]['subject']);
    }
}
