<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhooks;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Webhooks\Signature;
use App\Fleet\Webhooks\WebhookReceiver;
use App\Fleet\Webhooks\WebhookSecrets;
use App\Fleet\Webhooks\WebhookService;
use App\Tests\Support\AdminTestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

/**
 * The public endpoint end to end, through the real kernel and the real security firewall: no identity, HMAC only.
 */
final class WebhookReceiverTest extends AdminTestCase
{
    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', defaultBranch: 'develop', issueIdRegexp: 'FLE-[0-9]+'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('jira-work'));
        $this->secret = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
    }

    private function service(): WebhookService
    {
        $service = $this->container()->get(WebhookService::class);
        self::assertInstanceOf(WebhookService::class, $service);

        return $service;
    }

    private function repository(): WebhookRepository
    {
        $repository = $this->container()->get(WebhookRepository::class);
        self::assertInstanceOf(WebhookRepository::class, $repository);

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $key = 'FLE-66', string $to = 'Dispatched'): array
    {
        return [
            'webhookEvent' => 'jira:issue_updated',
            'issue' => ['key' => $key],
            'changelog' => ['items' => [['field' => 'status', 'fromString' => 'To Do', 'toString' => $to]]],
        ];
    }

    /**
     * @param array<string, string> $headers extra server parameters (HTTP_*)
     */
    private function call(string $body, ?string $signature, string $source = 'jira', string $project = 'blog', array $headers = []): int
    {
        $server = ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp, 'CONTENT_TYPE' => 'application/json'] + $headers;
        if (null !== $signature) {
            $server['HTTP_X_HUB_SIGNATURE'] = $signature;
        }
        $this->client->request('POST', self::BASE.'/hooks/'.$source.'/'.$project, server: $server, content: $body);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->body(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<WebhookDelivery>
     */
    private function deliveries(): array
    {
        $this->em();

        return $this->repository()->deliveries('blog', false, 100);
    }

    public function testASignedEventMatchingARuleIsAcceptedAndRecordedAsADecision(): void
    {
        $body = (string) json_encode(self::event());

        $status = $this->call($body, Signature::sign($body, $this->secret), headers: ['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER' => 'abc-1']);

        self::assertSame(200, $status);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $json = $this->json();
        self::assertSame('accepted', $json['result']);
        self::assertSame('would deploy fle-66 (template jira-work, branch develop)', $json['decision']);
        self::assertFalse($json['performed']);
        self::assertSame('deploy action not available until M0.04', $json['note']);

        $rows = $this->deliveries();
        self::assertCount(1, $rows);
        self::assertSame(WebhookDelivery::ACCEPTED, $rows[0]->result());
        self::assertSame('FLE-66', $rows[0]->subject());
        self::assertSame('To Do -> Dispatched', $rows[0]->change());
        self::assertSame('on_status=Dispatched', $rows[0]->rule());
        self::assertSame('deploy', $rows[0]->action());
        self::assertSame('would deploy fle-66 (template jira-work, branch develop)', $rows[0]->decision());
        self::assertSame('deploy action not available until M0.04', $rows[0]->reason());
        self::assertSame('abc-1', $rows[0]->dedupeId());
    }

    public function testABadMissingOrForeignSignatureIs401AndLeavesNoDeliveryRow(): void
    {
        $body = (string) json_encode(self::event());

        self::assertSame(401, $this->call($body, Signature::sign($body, 'not-the-secret')));
        self::assertSame(['error' => 'unauthorized'], $this->json());
        self::assertSame(401, $this->call($body, null), 'missing');
        self::assertSame(401, $this->call($body, 'sha256=nothex'), 'malformed');
        self::assertSame(401, $this->call($body.' ', Signature::sign($body, $this->secret)), 'the body changed after signing');
        self::assertSame([], $this->deliveries());
    }

    public function testUnknownProjectOrNoReceiverIs404WithoutRevealingAnything(): void
    {
        $body = '{}';

        self::assertSame(404, $this->call($body, Signature::sign($body, $this->secret), project: 'nope'));
        self::assertSame(['error' => 'not found'], $this->json());
        self::assertSame(404, $this->call($body, Signature::sign($body, $this->secret), source: 'bitbucket'), 'no Bitbucket receiver for blog');
        self::assertSame(404, $this->call($body, null, source: 'gitlab'), 'unknown source');
    }

    public function testTheJiraSecretDoesNotOpenTheBitbucketReceiver(): void
    {
        $bitbucketSecret = $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
        $body = '{}';

        self::assertSame(401, $this->call($body, Signature::sign($body, $this->secret), source: 'bitbucket'));
        self::assertSame(400, $this->call($body, Signature::sign($body, $bitbucketSecret), source: 'bitbucket'), 'signed, but no X-Event-Key');
    }

    public function testABodyOverOneMebibyteIs413BeforeAnythingElse(): void
    {
        $body = str_repeat('a', WebhookReceiver::MAX_BODY_BYTES + 1);

        self::assertSame(413, $this->call($body, Signature::sign($body, $this->secret)));
        self::assertSame(['error' => 'payload too large'], $this->json());
        self::assertSame([], $this->deliveries());
    }

    public function testABodyAtTheCapIsStillRead(): void
    {
        $body = '{"pad":"'.str_repeat('a', WebhookReceiver::MAX_BODY_BYTES - 10).'"}';
        self::assertSame(WebhookReceiver::MAX_BODY_BYTES, \strlen($body));

        self::assertSame(200, $this->call($body, Signature::sign($body, $this->secret)));
        self::assertSame('ignored', $this->json()['result']);
    }

    public function testARetryWithTheSameDeliveryIdIsADuplicate(): void
    {
        $body = (string) json_encode(self::event());
        $signature = Signature::sign($body, $this->secret);
        $headers = ['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER' => 'same-id'];

        self::assertSame(200, $this->call($body, $signature, headers: $headers));
        self::assertSame('accepted', $this->json()['result']);
        self::assertSame(200, $this->call($body, $signature, headers: $headers));
        self::assertSame(['result' => 'duplicate'], $this->json());

        $results = array_map(static fn (WebhookDelivery $d): string => $d->result(), $this->deliveries());
        sort($results);
        self::assertSame(['accepted', 'duplicate'], $results);

        // A deliberate second dispatch carries a new id and is accepted again.
        self::assertSame(200, $this->call($body, $signature, headers: ['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER' => 'other-id']));
        self::assertSame('accepted', $this->json()['result']);
    }

    public function testADeliveryWithoutAnIdIsNotDeduplicated(): void
    {
        $body = (string) json_encode(self::event());
        $signature = Signature::sign($body, $this->secret);

        $this->call($body, $signature);
        $this->call($body, $signature);

        self::assertSame(['accepted', 'accepted'], array_map(static fn (WebhookDelivery $d): string => $d->result(), $this->deliveries()));
    }

    public function testOnlyMatchedDeliveriesConsumeTheirId(): void
    {
        $ignored = (string) json_encode(self::event(to: 'Done'));
        $headers = ['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER' => 'id-1'];

        self::assertSame(200, $this->call($ignored, Signature::sign($ignored, $this->secret), headers: $headers));
        self::assertSame('ignored', $this->json()['result']);
        self::assertSame(200, $this->call($ignored, Signature::sign($ignored, $this->secret), headers: $headers));
        self::assertSame('ignored', $this->json()['result'], 'an ignored delivery is never a duplicate');
    }

    public function testDedupeIdsOlderThanSevenDaysAreFreedAndTheLogIsPruned(): void
    {
        $repository = $this->repository();
        $old = new \DateTimeImmutable('-8 days');
        $delivery = new WebhookDelivery($old, 'blog', WebhookSource::Jira, 'e', 'old-id', WebhookDelivery::ACCEPTED);
        $delivery->consumeDedupeId();
        $repository->saveDelivery($delivery);
        $ancient = new WebhookDelivery(new \DateTimeImmutable('-61 days'), 'blog', WebhookSource::Jira, 'e', null, WebhookDelivery::IGNORED);
        $repository->saveDelivery($ancient);

        $repository->prune(new \DateTimeImmutable('-7 days'), new \DateTimeImmutable('-60 days'));

        $this->em();
        self::assertFalse($this->repository()->dedupeSeen('blog', WebhookSource::Jira, 'old-id'));
        self::assertCount(1, $this->deliveries(), 'only the 61 days old row is gone');
    }

    public function testASignedBodyThatIsNotAJsonObjectIs400AndLogged(): void
    {
        $body = '[1,2,3]';

        self::assertSame(400, $this->call($body, Signature::sign($body, $this->secret)));
        self::assertSame(['error' => 'the body is not a JSON object'], $this->json());
        self::assertSame(['failed'], array_map(static fn (WebhookDelivery $d): string => $d->result(), $this->deliveries()));
    }

    public function testAJiraUpdateWithoutAnIssueKeyIs400(): void
    {
        $event = self::event();
        unset($event['issue']);
        $body = (string) json_encode($event);

        self::assertSame(400, $this->call($body, Signature::sign($body, $this->secret)));
        self::assertSame(['error' => 'The event has no issue key.'], $this->json());
    }

    public function testAnIssueKeyOutsideTheProjectPatternIsIgnored(): void
    {
        $body = (string) json_encode(self::event('OTHER-1'));

        self::assertSame(200, $this->call($body, Signature::sign($body, $this->secret)));
        self::assertSame(['result' => 'ignored', 'reason' => 'issue key does not match the project issue id pattern'], $this->json());
    }

    public function testRotationMakesTheOldSecretStopWorking(): void
    {
        $body = (string) json_encode(self::event());
        self::assertSame(200, $this->call($body, Signature::sign($body, $this->secret)));

        $new = $this->service()->issueSecret('blog', WebhookSource::Jira, true);

        self::assertNotSame($this->secret, $new);
        self::assertSame(401, $this->call($body, Signature::sign($body, $this->secret)), 'the old secret');
        self::assertSame(200, $this->call($body, Signature::sign($body, $new)), 'the new secret');
    }

    public function testRejectedCallsAreWarnedAboutWithoutTheBodyOrTheSignature(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{string, string, array<mixed>}> $records */
            public function __construct(public array &$records)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $clock = $this->container()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $secrets = $this->container()->get(WebhookSecrets::class);
        self::assertInstanceOf(WebhookSecrets::class, $secrets);
        $matcher = $this->container()->get(\App\Fleet\Webhooks\RuleMatcher::class);
        self::assertInstanceOf(\App\Fleet\Webhooks\RuleMatcher::class, $matcher);
        $action = $this->container()->get(\App\Fleet\Webhooks\WebhookAction::class);
        self::assertInstanceOf(\App\Fleet\Webhooks\WebhookAction::class, $action);
        $receiver = new WebhookReceiver($this->repository(), $secrets, $matcher, $action, $clock, $logger);

        $result = $receiver->receive(WebhookSource::Jira, 'blog', '{"private":"payload"}', ['x-hub-signature' => 'sha256='.str_repeat('0', 64), 'x-atlassian-webhook-identifier' => str_repeat('i', 200)]);

        self::assertSame(401, $result->status);
        self::assertCount(1, $records);
        self::assertSame('warning', $records[0][0]);
        self::assertSame(['source' => 'jira', 'project' => 'blog', 'delivery_id' => str_repeat('i', 64)], $records[0][2]);
        self::assertStringNotContainsString('payload', json_encode($records, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(str_repeat('0', 64), json_encode($records, \JSON_THROW_ON_ERROR));
    }

    public function testBitbucketRulesDecideARunPipeline(): void
    {
        $secret = $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
        $this->service()->addRule('blog', WebhookSource::Bitbucket, WebhookRule::ACTION_RUN_PIPELINE, ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog', 'branch' => 'renovate/*'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $body = (string) json_encode(['repository' => ['full_name' => 'acme/blog'], 'pullrequest' => ['source' => ['branch' => ['name' => 'renovate/lodash']]]]);

        $status = $this->call($body, Signature::sign($body, $secret), source: 'bitbucket', headers: ['HTTP_X_EVENT_KEY' => 'pullrequest:approved', 'HTTP_X_REQUEST_UUID' => 'uuid-1']);

        self::assertSame(200, $status);
        self::assertSame('would run pipeline renovate-merge on develop (acme/blog)', $this->json()['decision']);
        self::assertSame(200, $this->call($body, Signature::sign($body, $secret), source: 'bitbucket', headers: ['HTTP_X_EVENT_KEY' => 'pullrequest:approved', 'HTTP_X_REQUEST_UUID' => 'uuid-1']));
        self::assertSame(['result' => 'duplicate'], $this->json());
    }

    public function testDeletingTheProjectRemovesItsReceiversAndRules(): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->delete('blog');

        $this->em();
        self::assertNull($this->repository()->endpoint('blog', WebhookSource::Jira));
        self::assertNull($this->repository()->findRule(1));
    }
}
