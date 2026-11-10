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
        new InstanceRepository($em)->createMany([$instance]);
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
        ];
        $seen = [];
        foreach ($calls as [$method, $uri, $body]) {
            $decoded = $this->call($method, $uri, $body);
            $response = $this->client->getResponse();
            $status = (string) $response->getStatusCode();
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
        foreach (['getInstance', 'listJobs', 'getJobLog', 'stopInstance', 'destroyInstancesBatch', 'retryJob', 'getUpgrade'] as $id) {
            self::assertArrayHasKey($id, $seen);
        }
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
