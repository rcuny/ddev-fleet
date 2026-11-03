<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhooks;

use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Webhooks\BitbucketPipelineStarter;
use App\Fleet\Webhooks\DeployWebhookAction;
use App\Fleet\Webhooks\PipelineWebhookAction;
use App\Fleet\Webhooks\RuleMatcher;
use App\Fleet\Webhooks\RuleWebhookAction;
use App\Fleet\Webhooks\Signature;
use App\Fleet\Webhooks\WebhookAction;
use App\Fleet\Webhooks\WebhookActionRequest;
use App\Fleet\Webhooks\WebhookActionResult;
use App\Fleet\Webhooks\WebhookReceiver;
use App\Fleet\Webhooks\WebhookSecrets;
use App\Fleet\Webhooks\WebhookService;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\WebhookTestLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What a matched rule really does: queued jobs for Jira and Bitbucket deploys, the pipeline call, and every failure
 * answered as a recorded 200 or a 422, never a 500.
 */
final class WebhookActionsTest extends AdminTestCase
{
    private const string TOKEN = 'ATCTT-super-secret-pipeline-token';

    private string $jiraSecret = '';
    private string $bitbucketSecret = '';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private WebhookTestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new WebhookTestLogger();
        $projects = $this->get(ProjectService::class);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', defaultBranch: 'develop'));
        $templates = $this->get(TemplateService::class);
        $templates->create('blog', new TemplateInput('jira-work'));
        $templates->create('blog', new TemplateInput('work'));
        $this->jiraSecret = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->bitbucketSecret = $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->container()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function service(): WebhookService
    {
        return $this->get(WebhookService::class);
    }

    /**
     * A receiver whose pipeline calls go to a MockHttpClient (answering $status) and whose log lines are collected.
     */
    private function receiver(int $status = 201, ?WebhookAction $action = null): WebhookReceiver
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return new MockResponse('{}', ['http_code' => $status]);
        });
        $action ??= new RuleWebhookAction(
            $this->get(DeployWebhookAction::class),
            new PipelineWebhookAction($this->get(WebhookSecrets::class), new BitbucketPipelineStarter($client)),
        );

        return new WebhookReceiver($this->get(WebhookRepository::class), $this->get(WebhookSecrets::class), $this->get(RuleMatcher::class), $action, $this->get(ClockInterface::class), $this->logger);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, mixed>}
     */
    private function send(WebhookReceiver $receiver, WebhookSource $source, array $payload, array $headers = [], ?string $secret = null): array
    {
        $body = (string) json_encode($payload);
        $secret ??= WebhookSource::Jira === $source ? $this->jiraSecret : $this->bitbucketSecret;
        $result = $receiver->receive($source, 'blog', $body, ['x-hub-signature' => Signature::sign($body, $secret)] + $headers);

        return [$result->status, $result->body];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jira(string $key = 'FLE-66', int $timestamp = 1): array
    {
        return [
            'webhookEvent' => 'jira:issue_updated',
            'timestamp' => $timestamp,
            'issue' => ['key' => $key],
            'changelog' => ['items' => [['field' => 'status', 'fromString' => 'To Do', 'toString' => 'Dispatched']]],
        ];
    }

    /**
     * @param list<array<string, mixed>|null> $branches `new` of each change; null deletes
     *
     * @return array<string, mixed>
     */
    private static function push(array $branches, string $uuid = ''): array
    {
        return ['repository' => ['full_name' => 'acme/blog'], 'uuid' => $uuid, 'push' => ['changes' => array_map(static fn (?array $new): array => ['new' => $new], $branches)]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function branch(string $name): array
    {
        return ['type' => 'branch', 'name' => $name];
    }

    /**
     * @return list<Job>
     */
    private function jobs(): array
    {
        /** @var list<Job> $jobs */
        $jobs = $this->em()->createQuery(\sprintf('SELECT j FROM %s j ORDER BY j.id', Job::class))->getResult();

        return $jobs;
    }

    /**
     * @return list<WebhookDelivery>
     */
    private function deliveries(): array
    {
        $this->em();

        return array_reverse($this->get(WebhookRepository::class)->deliveries('blog', false, 100));
    }

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     */
    private function pushRule(array $match, array $params): void
    {
        $this->service()->addRule('blog', WebhookSource::Bitbucket, 'deploy', ['on_event' => 'repo:push', 'repo' => 'acme/blog'] + $match, ['template' => 'work'] + $params);
    }

    private function setToken(): void
    {
        $this->service()->setPipelineToken('blog', self::TOKEN);
    }

    public function testASignedJiraMoveQueuesADeployJobForTheKeyAndAnIdenticalRetryQueuesNone(): void
    {
        $receiver = $this->receiver();
        $headers = ['x-atlassian-webhook-identifier' => 'id-1'];

        [$status, $body] = $this->send($receiver, WebhookSource::Jira, self::jira(), $headers);

        self::assertSame(200, $status);
        self::assertSame('accepted', $body['result']);
        self::assertSame('blog--fle-66', $body['instance']);
        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame('deploy', $jobs[0]->type());
        self::assertSame(JobTrigger::Webhook, $jobs[0]->trigger());
        self::assertSame('webhook:jira', $jobs[0]->requestedBy());
        self::assertSame('blog', $jobs[0]->projectName());
        self::assertSame('blog--fle-66', $jobs[0]->instanceId());
        self::assertSame('jira-work', $jobs[0]->params()['template']);
        self::assertSame('develop', $jobs[0]->params()['branch']);
        self::assertSame($jobs[0]->id(), $body['job']);
        self::assertSame($jobs[0]->id(), $this->deliveries()[0]->jobId());

        [, $again] = $this->send($receiver, WebhookSource::Jira, self::jira(), $headers);
        self::assertSame(['result' => 'duplicate'], $again);
        self::assertCount(1, $this->jobs());
    }

    public function testAFixedLabelInstanceThatIsBusyRefusesTheReplaceInTheNote(): void
    {
        $this->pushRule(['branch' => 'develop'], ['label' => 'pw-regression']);
        $receiver = $this->receiver();
        $headers = ['x-event-key' => 'repo:push'];

        [, $first] = $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'a'), $headers + ['x-request-uuid' => 'u1']);
        self::assertSame('blog--pw-regression', $first['instance']);
        self::assertSame('deploy', $this->jobs()[0]->type());

        // A job of that instance is queued or running (the tests run jobs inline, so put one in the table).
        $em = $this->em();
        $em->persist(new Job('redeploy', 'blog--pw-regression', 'blog', JobTrigger::Cli, 'admin', [], null, false, null, new \DateTimeImmutable()));
        $em->flush();

        [$status, $second] = $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'b'), $headers + ['x-request-uuid' => 'u2']);
        self::assertSame(200, $status);
        self::assertSame('failed', $second['result']);
        self::assertStringContainsString('Busy', $second['note']);
        self::assertCount(2, $this->jobs(), 'nothing was queued on top of the running job');
    }

    public function testAFinishedFixedLabelInstanceIsRedeployedNotDuplicated(): void
    {
        $this->pushRule(['branch' => 'develop'], ['label' => 'pw-regression']);
        $receiver = $this->receiver();
        $headers = ['x-event-key' => 'repo:push'];
        $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'a'), $headers + ['x-request-uuid' => 'u1']);

        [, $second] = $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'b'), $headers + ['x-request-uuid' => 'u2']);

        self::assertSame('accepted', $second['result']);
        self::assertSame('blog--pw-regression', $second['instance']);
        self::assertStringStartsWith('redeploy queued', $second['note']);
        $types = array_map(static fn (Job $j): string => $j->type(), $this->jobs());
        self::assertSame(['deploy', 'redeploy'], $types);
    }

    public function testAFixedLabelRedeployCarriesTheRuleBranchOnlyWhenItChanged(): void
    {
        $this->pushRule(['branch' => 'develop*'], ['label' => 'pw-regression']);
        $receiver = $this->receiver();
        $headers = ['x-event-key' => 'repo:push'];
        $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'a'), $headers + ['x-request-uuid' => 'u1']);
        $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop')], 'b'), $headers + ['x-request-uuid' => 'u2']);
        $this->send($receiver, WebhookSource::Bitbucket, self::push([self::branch('develop-2')], 'c'), $headers + ['x-request-uuid' => 'u3']);

        $jobs = $this->jobs();
        self::assertSame(['deploy', 'redeploy', 'redeploy'], array_map(static fn (Job $j): string => $j->type(), $jobs));
        self::assertArrayNotHasKey('branch', $jobs[1]->params(), 'same branch: the job is as before');
        self::assertSame('develop-2', $jobs[2]->params()['branch']);
    }

    public function testAPrefixedPushNeverOverwritesAndAllocatesTheNextLabel(): void
    {
        $this->pushRule(['branch' => '*--pw-run'], ['label_prefix' => 'pw-', 'strip_suffix' => '--pw-run']);
        $receiver = $this->receiver();
        $headers = ['x-event-key' => 'repo:push'];
        $payload = self::push([self::branch('feature/x--pw-run')]);

        [, $first] = $this->send($receiver, WebhookSource::Bitbucket, $payload, $headers + ['x-request-uuid' => 'u1']);
        [, $second] = $this->send($receiver, WebhookSource::Bitbucket, $payload + ['n' => 2], $headers + ['x-request-uuid' => 'u2']);

        self::assertSame('blog--pw-feature-x', $first['instance']);
        self::assertSame('blog--pw-feature-x-1', $second['instance']);
        self::assertSame(['deploy', 'deploy'], array_map(static fn (Job $j): string => $j->type(), $this->jobs()));
        self::assertSame('feature/x--pw-run', $this->jobs()[0]->params()['branch']);
    }

    public function testAPushOfSeveralBranchesDeploysEachOnce(): void
    {
        $this->pushRule(['branch' => 'feature/*'], []);

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, self::push([self::branch('feature/a'), self::branch('feature/b'), null]), ['x-event-key' => 'repo:push']);

        self::assertSame(200, $status);
        self::assertCount(2, $body['actions']);
        self::assertSame(['blog--feature-a', 'blog--feature-b'], array_map(static fn (Job $j): ?string => $j->instanceId(), $this->jobs()));
    }

    public function testALongBranchNameStillGetsAnInstanceThatFits(): void
    {
        $this->pushRule(['branch' => 'feature/*'], []);
        $branch = 'feature/'.str_repeat('a-really-long-branch-name-', 5);

        [, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, self::push([self::branch($branch)]), ['x-event-key' => 'repo:push']);

        self::assertSame('accepted', $body['result']);
        self::assertLessThanOrEqual(63, \strlen($body['instance']));
        self::assertMatchesRegularExpression('/-[0-9a-f]{6}$/', $body['instance']);
    }

    public function testADeletionOnlyPushIsIgnoredAndStartsNothing(): void
    {
        $this->pushRule(['branch' => '*'], []);

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, self::push([null]), ['x-event-key' => 'repo:push']);

        self::assertSame(200, $status);
        self::assertSame('ignored', $body['result']);
        self::assertSame([], $this->jobs());
    }

    public function testTheCollisionGuardSkipsADerivedLabelOnAFixedOneAndLogsItsOwnRow(): void
    {
        $this->pushRule(['branch' => 'develop'], ['label' => 'pw-regression']);
        $this->pushRule(['branch' => 'regression'], ['label_prefix' => 'pw-']);

        [, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, self::push([self::branch('regression'), self::branch('develop')]), ['x-event-key' => 'repo:push']);

        self::assertSame('blog--pw-regression', $body['instance']);
        self::assertCount(1, $this->jobs());
        $rows = array_filter($this->deliveries(), static fn (WebhookDelivery $d): bool => WebhookDelivery::IGNORED === $d->result());
        self::assertCount(1, $rows);
        self::assertSame('collides with a fixed-label rule', array_values($rows)[0]->reason());
        self::assertSame('regression', array_values($rows)[0]->subject());
    }

    public function testAnUnresolvableBranchIs422AndConsumesNothing(): void
    {
        $this->pushRule(['branch' => '*'], []);
        $receiver = $this->receiver();
        $payload = self::push([self::branch('///')]);

        [$status, $body] = $this->send($receiver, WebhookSource::Bitbucket, $payload, ['x-event-key' => 'repo:push', 'x-request-uuid' => 'u1']);

        self::assertSame(422, $status);
        self::assertArrayHasKey('error', $body);
        self::assertSame([], $this->jobs());
        $rows = $this->deliveries();
        self::assertSame(WebhookDelivery::FAILED, $rows[0]->result());
        self::assertNull($rows[0]->dedupeId(), 'the sender can retry');
    }

    public function testRunPipelineCallsTheApiOnceWithTheBearerToken(): void
    {
        $this->service()->addRule('blog', WebhookSource::Bitbucket, WebhookRule::ACTION_RUN_PIPELINE, ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog', 'branch' => 'renovate/*'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $this->setToken();
        $payload = ['repository' => ['full_name' => 'acme/blog'], 'pullrequest' => ['source' => ['branch' => ['name' => 'renovate/lodash']]]];

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, $payload, ['x-event-key' => 'pullrequest:approved']);

        self::assertSame(200, $status);
        self::assertSame('accepted', $body['result']);
        self::assertSame('pipeline renovate-merge started on develop (acme/blog)', $body['note']);
        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://api.bitbucket.org/2.0/repositories/acme/blog/pipelines/', $url);
        self::assertContains('Authorization: Bearer '.self::TOKEN, $options['headers']);
        self::assertSame(['target' => ['type' => 'pipeline_ref_target', 'ref_type' => 'branch', 'ref_name' => 'develop', 'selector' => ['type' => 'custom', 'pattern' => 'renovate-merge']]], json_decode($options['body'], true));
        self::assertSame([], $this->jobs());
    }

    public function testRunPipelineWithoutATokenMakesNoCallAndSaysSo(): void
    {
        $this->service()->addRule('blog', WebhookSource::Bitbucket, WebhookRule::ACTION_RUN_PIPELINE, ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $payload = ['repository' => ['full_name' => 'acme/blog']];

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, $payload, ['x-event-key' => 'pullrequest:approved']);

        self::assertSame(200, $status);
        self::assertSame('failed', $body['result']);
        self::assertStringContainsString('no pipeline token', $body['note']);
        self::assertSame([], $this->requests);
        self::assertSame(WebhookDelivery::FAILED, $this->deliveries()[0]->result());
        self::assertStringContainsString('no pipeline token', (string) $this->deliveries()[0]->reason());
    }

    public function testABitbucketRefusalIsRecordedAndNeverTheTokenOrAStackTrace(): void
    {
        $this->service()->addRule('blog', WebhookSource::Bitbucket, WebhookRule::ACTION_RUN_PIPELINE, ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $this->setToken();

        [$status, $body] = $this->send($this->receiver(401), WebhookSource::Bitbucket, ['repository' => ['full_name' => 'acme/blog']], ['x-event-key' => 'pullrequest:approved']);

        self::assertSame(200, $status);
        self::assertSame('failed', $body['result']);
        self::assertSame('no pipeline started: Bitbucket answered HTTP 401', $body['note']);
        $this->assertNoSecretAnywhere($body);
    }

    public function testNoTokenAppearsInAnyAnswerRowOrLog(): void
    {
        $this->service()->addRule('blog', WebhookSource::Bitbucket, WebhookRule::ACTION_RUN_PIPELINE, ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog'], ['pattern' => 'p', 'ref' => 'develop']);
        $this->setToken();

        foreach ([201, 500] as $status) {
            [, $body] = $this->send($this->receiver($status), WebhookSource::Bitbucket, ['repository' => ['full_name' => 'acme/blog'], 'n' => $status], ['x-event-key' => 'pullrequest:approved']);
            $this->assertNoSecretAnywhere($body);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertNoSecretAnywhere(array $body): void
    {
        $dump = json_encode([$body, $this->logger->records, array_map(static fn (WebhookDelivery $d): array => [$d->reason(), $d->decision(), $d->subject(), $d->rule()], $this->deliveries())], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('Bearer', $dump);
        self::assertStringNotContainsString('.php', $dump, 'no stack trace');
    }

    public function testADeployRefusalIsRecordedAs200NotA500(): void
    {
        $rule = $this->get(WebhookRepository::class)->endpoint('blog', WebhookSource::Jira)?->rules()[0];
        self::assertNotNull($rule);
        // The template is deleted after the rule was added.
        $this->get(TemplateService::class)->delete('blog', 'jira-work');

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Jira, self::jira());

        self::assertSame(200, $status);
        self::assertSame('failed', $body['result']);
        self::assertFalse($body['performed']);
        self::assertStringStartsWith('not deployed:', $body['note']);
        self::assertSame([], $this->jobs());
        $row = $this->deliveries()[0];
        self::assertSame(WebhookDelivery::FAILED, $row->result());
        self::assertStringStartsWith('not deployed:', (string) $row->reason());
    }

    public function testAProjectWithNoDefaultBranchRefusesAJiraDeployInTheNote(): void
    {
        $projects = $this->get(ProjectService::class);
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        $this->get(TemplateService::class)->create('shop', new TemplateInput('jira-work'));
        $secret = $this->service()->issueSecret('shop', WebhookSource::Jira, false);
        $this->service()->addRule('shop', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $body = (string) json_encode(self::jira('SHOP-1'));

        $result = $this->receiver()->receive(WebhookSource::Jira, 'shop', $body, ['x-hub-signature' => Signature::sign($body, $secret)]);

        self::assertSame(200, $result->status);
        self::assertSame('failed', $result->body['result']);
        self::assertStringContainsString('no default branch', (string) $result->body['note']);
    }

    public function testAnActionThatThrowsIsAGenericNoteAndALogLineWithoutTheMessage(): void
    {
        $throwing = new class implements WebhookAction {
            public function execute(WebhookActionRequest $request): WebhookActionResult
            {
                throw new \RuntimeException('boom at /var/www/secret/path with token ATCTT-leak');
            }
        };

        [$status, $body] = $this->send($this->receiver(action: $throwing), WebhookSource::Jira, self::jira());

        self::assertSame(200, $status);
        self::assertSame('failed', $body['result']);
        self::assertSame('the action failed unexpectedly; see the application log', $body['note']);
        self::assertStringNotContainsString('boom', json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertSame(\RuntimeException::class, $this->logger->records[0][2]['error']);
        self::assertStringNotContainsString('ATCTT-leak', json_encode($this->logger->records, \JSON_THROW_ON_ERROR));
        self::assertSame(WebhookDelivery::FAILED, $this->deliveries()[0]->result());
        // The delivery stays claimed: the sender's retry is a duplicate, not a second attempt.
        [, $retry] = $this->send($this->receiver(action: $throwing), WebhookSource::Jira, self::jira(), ['x-atlassian-webhook-identifier' => 'x']);
        self::assertSame(['result' => 'duplicate'], $retry);
    }

    public function testAReplayedBodyWithAFreshDeliveryIdIsADuplicateInsideTheWindowOnly(): void
    {
        $receiver = $this->receiver();
        $this->send($receiver, WebhookSource::Jira, self::jira(), ['x-atlassian-webhook-identifier' => 'id-1']);

        [$status, $replay] = $this->send($receiver, WebhookSource::Jira, self::jira(), ['x-atlassian-webhook-identifier' => 'a-fresh-id']);
        self::assertSame(200, $status);
        self::assertSame(['result' => 'duplicate'], $replay);
        self::assertCount(1, $this->jobs());
        $reasons = array_map(static fn (WebhookDelivery $d): ?string => $d->reason(), array_filter($this->deliveries(), static fn (WebhookDelivery $d): bool => WebhookDelivery::DUPLICATE === $d->result()));
        self::assertSame(['this body was already handled'], array_values($reasons));

        // Outside the window the same body is a new event again (the documented limit).
        $this->em()->createQuery(\sprintf('UPDATE %s d SET d.receivedAt = :old', WebhookDelivery::class))->setParameter('old', new \DateTimeImmutable('-2 hours'), \App\Doctrine\UtcDateTimeImmutableType::NAME)->execute();
        [, $later] = $this->send($receiver, WebhookSource::Jira, self::jira(), ['x-atlassian-webhook-identifier' => 'another-id']);
        self::assertSame('accepted', $later['result']);
    }

    public function testAnOversizedContentLengthIs413BeforeTheBodyIsRead(): void
    {
        $read = false;
        $result = $this->receiver()->receive(WebhookSource::Jira, 'blog', static function () use (&$read): string {
            $read = true;

            return '{}';
        }, ['content-length' => (string) (WebhookReceiver::MAX_BODY_BYTES + 1)]);

        self::assertSame(413, $result->status);
        self::assertFalse($read);
    }

    public function testTheBodyIsReadWhenTheDeclaredLengthIsWithinTheCap(): void
    {
        $body = '{}';
        $result = $this->receiver()->receive(WebhookSource::Jira, 'blog', static fn (): string => $body, ['content-length' => '2', 'x-hub-signature' => Signature::sign($body, $this->jiraSecret)]);

        self::assertSame(200, $result->status);
        self::assertSame('ignored', $result->body['result']);
    }

    public function testAReceiverWithNoRulesAnswers200IgnoredNot404(): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Bitbucket, true);
        $secret = $this->service()->issueSecret('blog', WebhookSource::Bitbucket, true);

        [$status, $body] = $this->send($this->receiver(), WebhookSource::Bitbucket, ['repository' => ['full_name' => 'acme/blog']], ['x-event-key' => 'repo:push'], $secret);

        self::assertSame(200, $status);
        self::assertSame('ignored', $body['result']);
    }

    public function testASecretThatCannotBeOpenedLogsADistinctErrorNotABadSignatureWarning(): void
    {
        // A receiver whose sealed secret was written under another key: opening it fails.
        $em = $this->em();
        $project = $this->get(\App\Domain\Project\ProjectRepository::class)->findByName('blog');
        self::assertNotNull($project);
        $endpoint = $this->get(WebhookRepository::class)->endpoint('blog', WebhookSource::Bitbucket);
        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        $endpoint->replaceSecret(random_bytes(48), random_bytes(24), 2, new \DateTimeImmutable());
        $em->flush();

        [$status] = $this->send($this->receiver(), WebhookSource::Bitbucket, [], ['x-event-key' => 'repo:push'], 'whatever');
        self::assertSame(401, $status);
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertStringContainsString('cannot be opened', $this->logger->records[0][1]);

        // A readable secret with a wrong signature stays a warning.
        $this->logger->clear();
        [$status] = $this->send($this->receiver(), WebhookSource::Jira, [], [], 'wrong');
        self::assertSame(401, $status);
        self::assertSame('warning', $this->logger->records[0][0]);
        self::assertStringContainsString('bad or missing signature', $this->logger->records[0][1]);
    }
}
