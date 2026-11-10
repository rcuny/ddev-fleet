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
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\OpenApiSchema;
use Symfony\Component\Routing\RouterInterface;

/**
 * FLE-278: the OpenAPI document (`resources/api/openapi.yaml`, served at `/api/v1/openapi.json`) and the API must not
 * drift. Every `/api/v1` route needs an operation in the spec and the other way round, every operation names its scope
 * and its responses, and the real responses of every endpoint fit the schemas.
 */
final class OpenApiContractTest extends AdminTestCase
{
    private IssuedToken $token;
    private IssuedToken $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeDefaults('develop', 'full');
        new ProjectRepository($em)->save($project);
        new TemplateRepository($em)->save(new Template($project, 'full'));
        $instance = new Instance($project, 'i1', 'full', 'develop', 'admin');
        $instance->changeState(InstanceState::Running);
        $extra = [new Instance($project, 'i2', 'full', 'develop', 'admin'), new Instance($project, 'i3', 'full', 'develop', 'admin'), new Instance($project, 'main', 'full', 'develop', 'admin')];
        foreach ($extra as $other) {
            $other->changeState(InstanceState::Running);
        }
        new InstanceRepository($em)->createMany([$instance, ...$extra]);
        $repo = new WebhookRepository($em);
        $repo->saveDelivery(new WebhookDelivery(new \DateTimeImmutable(), 'acme', WebhookSource::Jira, 'jira:issue_updated', 'd-1', WebhookDelivery::ACCEPTED, null, 'FLE-1', null, null, 'deploy'));
        $repo->flush();

        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);
        $this->token = $issuer->issue('contract', 'test', [ApiScope::Read], ['*'], [], [], new \DateTimeImmutable('+30 days'), 'cli');
        $this->writer = $issuer->issue('writer', 'test', [ApiScope::Read, ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy], ['acme'], [], [], new \DateTimeImmutable('+30 days'), 'cli');
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $this->call('GET', '/api/v1/openapi.json');
        self::assertResponseStatusCodeSame(200);
        $spec = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($spec);
        self::assertSame('3.1.0', $spec['openapi']);

        return $spec;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|string
     */
    private function call(string $method, string $uri, string $body = '', ?string $token = null, array $headers = []): array|string
    {
        $auth = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request($method, $uri, server: ($auth ?: ['HTTP_AUTHORIZATION' => 'Bearer '.('GET' === $method ? $this->token : $this->writer)->secret]) + ['REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none', 'CONTENT_TYPE' => 'application/json'] + $headers, content: $body);
        $raw = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : $raw;
    }

    /**
     * @return list<string> "METHOD /path" for every API route
     */
    private function routes(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $out = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/v1') || 'api_no_such_route' === $name) {
                continue;
            }
            foreach ($route->getMethods() ?: ['ANY'] as $method) {
                $out[] = $method.' '.$route->getPath();
            }
        }
        sort($out);

        return $out;
    }

    public function testEveryRouteIsDescribedAndEverySpecOperationHasARoute(): void
    {
        $spec = $this->spec();
        $described = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $described[] = strtoupper($method).' '.$path;
                self::assertNotEmpty($operation['operationId'] ?? null, "$method $path: operationId");
                self::assertNotEmpty($operation['summary'] ?? null, "$method $path: summary");
                self::assertNotEmpty($operation['x-scope'] ?? null, "$method $path: x-scope");
                self::assertNotEmpty($operation['responses'] ?? null, "$method $path: responses");
            }
        }
        sort($described);

        self::assertSame($this->routes(), $described, 'The routes and openapi.yaml disagree: add or remove the operation.');
        $ids = array_merge(...array_map(static fn (array $ops): array => array_column($ops, 'operationId'), array_values($spec['paths'])));
        self::assertSame($ids, array_values(array_unique($ids)), 'operationIds must be unique');
    }

    public function testSpecIsReadableByAnyReadTokenButNotAnonymously(): void
    {
        $this->call('GET', '/api/v1/openapi.json', token: 'flt_nope');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRealResponsesFitTheirSchemas(): void
    {
        $spec = $this->spec();
        $schema = new OpenApiSchema($spec);
        $em = $this->em();
        $job = new Job('start', 'acme--i1', 'acme', JobTrigger::Cli, 'admin', []);
        $job->markRunning(new \DateTimeImmutable());
        $job->finish(JobStatus::Failed, 1, 'boom', new \DateTimeImmutable());
        new JobRepository($em)->add($job);
        $created = new InstanceRepository($em)->find('acme--i1')?->createdAt()->format(\DATE_ATOM) ?? '';
        $jid = (string) $job->id();
        $i3Created = new InstanceRepository($em)->find('acme--i3')?->createdAt()->format(\DATE_ATOM) ?? '';
        $readOnly = $this->token->secret;

        $calls = [
            ['GET', '/api/v1/openapi.json', ''],
            ['GET', '/api/v1/tokens/self', ''],
            ['GET', '/api/v1/host', ''],
            ['GET', '/api/v1/host/capacity', ''],
            ['GET', '/api/v1/host/capacity?project=acme', ''],
            ['GET', '/api/v1/upgrade', ''],
            ['GET', '/api/v1/webhooks/log', ''],
            ['GET', '/api/v1/projects', ''],
            ['GET', '/api/v1/projects/acme', ''],
            ['GET', '/api/v1/projects/acme/templates', ''],
            ['GET', '/api/v1/templates/acme/full', ''],
            ['GET', '/api/v1/instances?limit=1', ''],
            ['GET', '/api/v1/instances/acme--i1', ''],
            ['GET', '/api/v1/jobs', ''],
            ['GET', '/api/v1/jobs/'.$jid, ''],
            ['GET', '/api/v1/jobs/'.$jid.'/log', ''],
            ['GET', '/api/v1/instances/nope', ''],
            ['POST', '/api/v1/instances', '{}'],
            ['POST', '/api/v1/instances/acme--i1/stop', ''],
            ['POST', '/api/v1/instances/acme--i1/start', ''],
            ['POST', '/api/v1/jobs/'.$jid.'/retry', ''],
            ['POST', '/api/v1/instances:destroy-batch', '{"ids":["acme--i1"],"if_match":{"acme--i1":"'.$created.'"}}'],
            // Error and conflict responses: each must be declared by its operation and fit the Problem schema.
            ['GET', '/api/v1/host', '', 'flt_nope', [], 401],
            ['POST', '/api/v1/instances/acme--i2/stop', '', $readOnly, [], 403],
            ['GET', '/api/v1/jobs/999999', '', null, [], 404],
            ['POST', '/api/v1/instances', '{}', null, [], 422],
            ['POST', '/api/v1/instances:destroy-batch', '{}', null, [], 422],
            ['POST', '/api/v1/instances/acme--i2/redeploy', '{}', null, [], 202],
            ['DELETE', '/api/v1/instances/acme--main', '', null, [], 409],
            ['DELETE', '/api/v1/instances/acme--i3', '', null, [], 428],
            ['DELETE', '/api/v1/instances/acme--i3', '', null, ['HTTP_IF_MATCH' => '2000-01-01T00:00:00+00:00'], 412],
            ['DELETE', '/api/v1/instances/acme--i3', '', null, ['HTTP_IF_MATCH' => $i3Created], 202],
            ['DELETE', '/api/v1/instances/nope', '', null, [], 404],
        ];
        $seen = [];
        foreach ($calls as $call) {
            [$method, $uri, $body, $bearer, $headers, $expected] = $call + [3 => null, 4 => [], 5 => null];
            $decoded = $this->call($method, $uri, $body, $bearer, $headers);
            $response = $this->client->getResponse();
            $status = (string) $response->getStatusCode();
            if (null !== $expected) {
                self::assertSame($expected, $response->getStatusCode(), "$method $uri");
            }
            $path = $this->specPath($spec, $method, $uri);
            $operation = $spec['paths'][$path][strtolower($method)];
            $declared = $schema->resolve($operation['responses'][$status] ?? $operation['responses']['default']);
            self::assertArrayHasKey('content', $declared, "$method $uri -> $status has no declared content");
            $type = strtolower(explode(';', (string) $response->headers->get('Content-Type'))[0]);
            self::assertArrayHasKey($type, $declared['content'], "$method $uri -> $status: undeclared content type $type");
            $payload = 'text/plain' === $type ? (string) $response->getContent() : $decoded;
            $problems = $schema->violations($payload, $declared['content'][$type]['schema']);
            self::assertSame([], $problems, "$method $uri -> $status does not fit its schema");
            foreach ($declared['headers'] ?? [] as $name => $header) {
                if ($header['required'] ?? false) {
                    self::assertTrue($response->headers->has($name), "$method $uri -> $status: missing header $name");
                }
            }
            $seen[$operation['operationId']] = true;
        }
        foreach (['getInstance', 'listJobs', 'getJobLog', 'stopInstance', 'destroyInstancesBatch', 'retryJob', 'getUpgrade', 'redeployInstance', 'destroyInstance'] as $id) {
            self::assertArrayHasKey($id, $seen);
        }
    }

    /**
     * Every operation's `x-scope` is what the code enforces: a token holding all but one of the listed scopes gets 403,
     * a token holding all of them does not.
     */
    public function testScopesDeclaredInTheSpecAreTheOnesEnforced(): void
    {
        $spec = $this->spec();
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);
        $em = $this->em();
        $job = new Job('start', 'acme--i1', 'acme', JobTrigger::Cli, 'admin', []);
        $job->markRunning(new \DateTimeImmutable());
        $job->finish(JobStatus::Failed, 1, 'boom', new \DateTimeImmutable());
        new JobRepository($em)->add($job);
        $uris = ['{project}' => 'acme', '{template}' => 'full', '{id}' => 'acme--i1'];
        $n = 0;
        $checked = 0;
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $scopes = (array) $operation['x-scope'];
                if (['any'] === $scopes) {
                    continue;
                }
                // Retry needs the scope of the retried job; the job here is a start.
                if ('retryJob' === $operation['operationId']) {
                    $scopes = ['lifecycle'];
                }
                $uri = strtr((string) $path, $uris);
                if (str_contains($uri, '/jobs/')) {
                    $uri = str_replace('acme--i1', (string) $job->id(), $uri);
                }
                $needed = array_map(static fn (string $s): ApiScope => ApiScope::from($s), $scopes);
                $read = ['read'] === $scopes;
                $variants = ['all' => $needed];
                foreach ($needed as $i => $_) {
                    $variants['without '.$scopes[$i]] = array_values(array_filter($needed, static fn (ApiScope $s): bool => $s !== $needed[$i]));
                }
                foreach ($variants as $label => $held) {
                    $isAll = 'all' === $label;
                    $held = [] === $held ? [ApiScope::Read] : array_values($held);
                    // A token without the base scope cannot carry the flag alone: add an unrelated one.
                    if (!$isAll && \in_array(ApiScope::LifecycleDestroy, $held, true) && !\in_array(ApiScope::Lifecycle, $held, true)) {
                        $held = [ApiScope::Deploy];
                    }
                    if ($read && !$isAll) {
                        $held = [ApiScope::Deploy];
                    }
                    $token = $issuer->issue('sc'.++$n, 'test', $held, $read && $isAll ? ['*'] : ['acme'], [], [], new \DateTimeImmutable('+30 days'), 'cli');
                    $this->call(strtoupper((string) $method), $uri, 'POST' === strtoupper((string) $method) ? '{}' : '', $token->secret, 'DELETE' === strtoupper((string) $method) ? ['HTTP_IF_MATCH' => 'x'] : []);
                    $status = $this->client->getResponse()->getStatusCode();
                    if ($isAll) {
                        self::assertNotSame(403, $status, "$method $path with $label");
                    } else {
                        self::assertSame(403, $status, "$method $path $label");
                    }
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(40, $checked);
    }

    /**
     * The spec path template that matches a concrete URI.
     *
     * @param array<string, mixed> $spec
     */
    private function specPath(array $spec, string $method, string $uri): string
    {
        $path = explode('?', $uri)[0];
        foreach (array_map('strval', array_keys($spec['paths'])) as $template) {
            if (isset($spec['paths'][$template][strtolower($method)]) && 1 === preg_match('#^'.preg_replace('#\{[^/}]+\}#', '[^/:]+', $template).'$#', $path)) {
                return $template;
            }
        }
        // An unknown instance id still belongs to the parametrised operation.
        foreach (array_map('strval', array_keys($spec['paths'])) as $template) {
            if (isset($spec['paths'][$template][strtolower($method)]) && 1 === preg_match('#^'.preg_replace('#\{[^/}]+\}#', '[^/]+', $template).'$#', $path)) {
                return $template;
            }
        }
        self::fail("No operation for $method $uri");
    }
}
