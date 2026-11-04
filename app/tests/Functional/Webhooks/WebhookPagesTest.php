<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhooks;

use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Webhooks\Signature;
use App\Fleet\Webhooks\WebhookService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebhookPagesTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', defaultBranch: 'develop'));
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('jira-work'));
    }

    private function service(): WebhookService
    {
        $service = $this->container()->get(WebhookService::class);
        self::assertInstanceOf(WebhookService::class, $service);

        return $service;
    }

    public function testThePageListsEveryProjectAndSourceWithItsAddress(): void
    {
        $crawler = $this->open('/admin/webhooks');

        self::assertResponseIsSuccessful();
        self::assertSame('Webhooks', trim($crawler->filter('h1')->text()));
        self::assertCount(4, $crawler->filter('[data-testid=webhook-endpoint]'), '2 projects x 2 sources');
        $urls = $crawler->filter('[data-testid=webhook-url]')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertContains(self::BASE.'/hooks/jira/blog', $urls);
        self::assertContains(self::BASE.'/hooks/bitbucket/shop', $urls);
        self::assertCount(4, $crawler->filter('[data-testid=webhook-create]'));
        self::assertCount(4, $crawler->filter('[data-testid=webhook-copy]'));
        self::assertCount(1, $crawler->filter('[data-testid=deliveries-empty]'));
    }

    public function testCreatingASecretShowsItOnceAndNeverAgain(): void
    {
        $this->post('/admin/projects/blog/webhooks/jira/secret', []);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $crawler = $this->client->getCrawler();
        $secret = (string) $crawler->filter('[data-testid=webhook-secret-value]')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);

        foreach (['/admin/webhooks', '/admin/projects/blog/webhooks'] as $path) {
            $this->open($path);
            self::assertStringNotContainsString($secret, $this->body(), $path);
        }
        $this->open('/admin/projects/blog/webhooks');
        self::assertSelectorExists('[data-testid=webhook-rotate]');
        self::assertSelectorNotExists('[data-source=jira] [data-testid=webhook-create]');

        // The secret really works against the receiver.
        $body = '{}';
        $this->client->request('POST', self::BASE.'/hooks/jira/blog', server: ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_HUB_SIGNATURE' => Signature::sign($body, $secret)], content: $body);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRotatingReplacesTheSecret(): void
    {
        $first = $this->service()->issueSecret('blog', WebhookSource::Jira, false);

        $this->post('/admin/projects/blog/webhooks/jira/secret', ['rotate' => '1']);

        self::assertResponseIsSuccessful();
        $second = (string) $this->client->getCrawler()->filter('[data-testid=webhook-secret-value]')->attr('value');
        self::assertNotSame($first, $second);
    }

    public function testASecondCreateWithoutRotateIsRefusedByTheService(): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);

        $this->expectException(\App\Fleet\Projects\Refused::class);
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);
    }

    public function testASecondCreatePostIsAWarningOnTheTabAndKeepsTheSecret(): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);

        $this->post('/admin/projects/blog/webhooks/jira/secret', []);

        self::assertResponseRedirects(self::BASE.'/admin/projects/blog/webhooks');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already has a webhook secret for Jira', $crawler->filter('[data-testid=flashes]')->text());
        self::assertCount(0, $crawler->filter('[data-testid=webhook-secret-once]'));
    }

    public function testThePostNeedsTheCsrfToken(): void
    {
        $this->client->request('POST', self::BASE.'/admin/projects/blog/webhooks/jira/secret', server: $this->browserServer());

        self::assertResponseStatusCodeSame(403);
        $this->service();
        $this->em();
        $repository = $this->container()->get(\App\Domain\Webhook\WebhookRepository::class);
        self::assertInstanceOf(\App\Domain\Webhook\WebhookRepository::class, $repository);
        self::assertNull($repository->endpoint('blog', WebhookSource::Jira));
    }

    public function testAnUnknownProjectIs404(): void
    {
        $this->open('/admin/projects/nope/webhooks');
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/nope/webhooks/jira/secret', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberAndAnAnonymousVisitorCannotUseAnyOfIt(): void
    {
        foreach (['member' => 403, 'none' => 401] as $role => $code) {
            $this->as($role);
            foreach (['/admin/webhooks', '/admin/projects/blog/webhooks'] as $path) {
                $this->open($path);
                self::assertResponseStatusCodeSame($code, $role.' '.$path);
            }
            $this->post('/admin/projects/blog/webhooks/jira/secret', []);
            self::assertResponseStatusCodeSame($code, $role.' POST');
        }
        $this->as('admin');
        $this->em();
        $repository = $this->container()->get(\App\Domain\Webhook\WebhookRepository::class);
        self::assertInstanceOf(\App\Domain\Webhook\WebhookRepository::class, $repository);
        self::assertSame([], $repository->endpoints());
    }

    public function testTheDeliveryLogShowsDecisionsAndCanHideIgnored(): void
    {
        $secret = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        foreach (['Dispatched', 'Done'] as $status) {
            $body = (string) json_encode(['webhookEvent' => 'jira:issue_updated', 'issue' => ['key' => 'FLE-9'], 'changelog' => ['items' => [['field' => 'status', 'fromString' => 'To Do', 'toString' => $status]]]]);
            $this->client->request('POST', self::BASE.'/hooks/jira/blog', server: ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_HUB_SIGNATURE' => Signature::sign($body, $secret)], content: $body);
        }

        $crawler = $this->open('/admin/projects/blog/webhooks');
        self::assertCount(2, $crawler->filter('[data-testid=delivery-row]'));
        self::assertStringContainsString('deploy fle-9 (template jira-work', $crawler->filter('[data-result=accepted] [data-testid=delivery-decision]')->text());
        self::assertCount(1, $crawler->filter('[data-testid=webhook-rules] li'));
        $outcome = $crawler->filter('[data-result=accepted] [data-testid=delivery-outcome]')->text();
        self::assertStringContainsString('deploy queued: blog--fle-9', $outcome);
        self::assertMatchesRegularExpression('/job #\d+/', $crawler->filter('[data-result=accepted] [data-testid=delivery-job]')->text());

        $crawler = $this->open('/admin/projects/blog/webhooks?hide-ignored=1');
        self::assertCount(1, $crawler->filter('[data-testid=delivery-row][data-result=accepted]'));

        $crawler = $this->open('/admin/webhooks');
        self::assertCount(2, $crawler->filter('[data-testid=delivery-row]'));
        self::assertSelectorTextContains('[data-testid=delivery-row]', 'blog');
    }

    private int $sequence = 0;

    /**
     * POSTs a signed delivery to the receiver.
     *
     * @param string|array<string, mixed> $payload
     * @param array<string, string>       $headers
     *
     * @return array{int, array<string, mixed>} status, decoded answer
     */
    private function hook(string $source, string $secret, string|array $payload, array $headers = [], ?string $signWith = null, string $project = 'blog'): array
    {
        $body = \is_string($payload) ? $payload : (string) json_encode($payload);
        $server = ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => Signature::sign($body, $signWith ?? $secret)] + $headers;
        $this->client->request('POST', self::BASE.'/hooks/'.$source.'/'.$project, server: $server, content: $body);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$this->client->getResponse()->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jiraMove(string $key, string $to): array
    {
        return ['webhookEvent' => 'jira:issue_updated', 'issue' => ['key' => $key], 'changelog' => ['items' => [['field' => 'status', 'fromString' => 'To Do', 'toString' => $to]]]];
    }

    /**
     * @return array<string, string>
     */
    private function jiraId(): array
    {
        return ['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER' => 'page-'.$this->sequence++];
    }

    /**
     * @return array<string, string>
     */
    private function bitbucketId(string $event): array
    {
        return ['HTTP_X_EVENT_KEY' => $event, 'HTTP_X_REQUEST_UUID' => 'page-'.$this->sequence++];
    }

    /**
     * Thirteen deliveries of every outcome; returns the two secrets.
     *
     * @return array{string, string}
     */
    private function logWithEveryOutcome(): array
    {
        $jira = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $bitbucket = $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $this->service()->addRule('blog', WebhookSource::Bitbucket, 'run-pipeline', ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $repo = ['repository' => ['full_name' => 'acme/blog']];

        $id = $this->jiraId();
        $this->hook('jira', $jira, self::jiraMove('PW-10', 'Dispatched'), $id);
        $this->hook('jira', $jira, self::jiraMove('PW-10', 'Dispatched'), $id);
        $this->hook('jira', $jira, self::jiraMove('PW-10', 'Dispatched'), $this->jiraId());
        $this->hook('jira', $jira, self::jiraMove('PW-11', 'Done'), $this->jiraId());
        $this->hook('jira', $jira, ['webhookEvent' => 'jira:issue_created', 'issue' => ['key' => 'PW-12']], $this->jiraId());
        $this->hook('jira', $jira, ['webhookEvent' => 'jira:issue_updated', 'issue' => ['key' => 'PW-13'], 'changelog' => ['items' => [['field' => 'summary', 'fromString' => 'a', 'toString' => 'b']]]], $this->jiraId());
        $this->hook('jira', $jira, '[1,2]', $this->jiraId());
        $this->hook('jira', $jira, 'not json', $this->jiraId());
        $this->hook('jira', $jira, ['webhookEvent' => 'jira:issue_updated'], $this->jiraId());
        $this->hook('bitbucket', $bitbucket, $repo);
        $this->hook('bitbucket', $bitbucket, $repo, $this->bitbucketId('pullrequest:approved'));
        $this->hook('bitbucket', $bitbucket, ['repository' => ['full_name' => 'acme/other']], $this->bitbucketId('pullrequest:approved'));
        $this->hook('bitbucket', $bitbucket, $repo, $this->bitbucketId('repo:fork'));

        return [$jira, $bitbucket];
    }

    public function testTheLogRecordsEveryOutcomeWithItsReasonAndTheFiltersNarrowIt(): void
    {
        $this->logWithEveryOutcome();

        $crawler = $this->open('/admin/projects/blog/webhooks');
        $count = static fn (string $result): int => $crawler->filter('[data-testid=delivery-row][data-result='.$result.']')->count();
        self::assertSame([1, 2, 5, 5], [$count('accepted'), $count('duplicate'), $count('ignored'), $count('failed')]);
        self::assertCount(13, $crawler->filter('[data-testid=delivery-row]'));
        $accepted = $crawler->filter('[data-result=accepted]');
        self::assertStringContainsString('PW-10', $accepted->text());
        self::assertStringContainsString('To Do -> Dispatched', $accepted->text());
        self::assertMatchesRegularExpression('/^job #\d+$/', trim($accepted->filter('[data-testid=delivery-job]')->first()->text()));
        self::assertStringContainsString('deploy queued', $accepted->filter('[data-testid=delivery-outcome]')->text());
        self::assertMatchesRegularExpression('/this (delivery id|body) was already handled/', $crawler->filter('[data-result=duplicate] [data-testid=delivery-outcome]')->first()->text());

        $outcomes = static fn (string $result): string => implode("\n", $crawler->filter('[data-result='.$result.'] [data-testid=delivery-outcome]')->each(static fn ($n): string => trim($n->text())));
        $phone = implode("\n", $crawler->filter('[data-testid=delivery-outcome-phone]')->each(static fn ($n): string => trim($n->text())));
        foreach (['no rule for this status', 'event is not an issue update', 'no status change'] as $reason) {
            self::assertSame(1, substr_count($outcomes('ignored'), $reason), $reason);
            self::assertStringContainsString($reason, $phone, 'the phone line carries the reason too');
        }
        self::assertSame(2, substr_count($outcomes('failed'), 'the body is not a JSON object'));
        self::assertSame(1, substr_count($outcomes('failed'), 'The event has no issue key.'));
        self::assertSame(1, substr_count($outcomes('failed'), 'The X-Event-Key header is missing.'));
        self::assertSame(1, substr_count($outcomes('failed'), 'no pipeline token'));
        self::assertMatchesRegularExpression('/^Last delivery \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC: \w+\.$/', trim($crawler->filter('[data-source=jira] [data-testid=webhook-latest]')->text()));

        // Filters: each outcome, a source, both, and the way back; the select keeps the choice.
        foreach (['accepted' => 1, 'duplicate' => 2, 'ignored' => 5, 'failed' => 5] as $result => $expected) {
            $page = $this->open('/admin/projects/blog/webhooks?result='.$result);
            self::assertCount($expected, $page->filter('[data-testid=delivery-row]'), $result);
            self::assertCount($expected, $page->filter('[data-testid=delivery-row][data-result='.$result.']'), $result);
            self::assertSame($result, $page->filter('select[name=result] option[selected]')->attr('value'));
        }
        $page = $this->open('/admin/projects/blog/webhooks?source=bitbucket');
        self::assertCount(4, $page->filter('[data-testid=delivery-row]'));
        self::assertSame('bitbucket', $page->filter('select[name=source] option[selected]')->attr('value'));
        $page = $this->open('/admin/projects/blog/webhooks?source=bitbucket&result=accepted');
        self::assertCount(0, $page->filter('[data-testid=delivery-row]'));
        self::assertCount(1, $page->filter('[data-testid=deliveries-empty]'));
        self::assertCount(1, $page->filter('[data-testid=delivery-filter-clear]'));
        self::assertCount(0, $this->open('/admin/projects/blog/webhooks')->filter('[data-testid=delivery-filter-clear]'));
        self::assertCount(13, $this->open('/admin/projects/blog/webhooks?source=gitlab&result=bogus')->filter('[data-testid=delivery-row]'), 'unknown values are no filter');

        // Hide ignored keeps the other outcomes, combines with a filter and survives "Clear filters".
        $page = $this->open('/admin/projects/blog/webhooks?hide-ignored=1');
        self::assertCount(0, $page->filter('[data-result=ignored]'));
        self::assertCount(8, $page->filter('[data-testid=delivery-row]'));
        self::assertSame('Show ignored', trim($page->filter('[data-testid=webhook-filter]')->text()));
        self::assertSame('Hide ignored', trim($this->open('/admin/projects/blog/webhooks')->filter('[data-testid=webhook-filter]')->text()));
        $page = $this->open('/admin/projects/blog/webhooks?hide-ignored=1&source=jira');
        self::assertCount(6, $page->filter('[data-testid=delivery-row]'));
        self::assertStringContainsString('hide-ignored=1', (string) $page->filter('[data-testid=delivery-filter-clear]')->attr('href'));

        // The overview: the same rows with the project, filters through the query, no paging.
        $page = $this->open('/admin/webhooks?source=jira&result=accepted');
        self::assertCount(1, $page->filter('[data-testid=delivery-row]'));
        self::assertStringContainsString('blog', $page->filter('[data-testid=delivery-row]')->text());
        self::assertCount(0, $page->filter('nav[aria-label="Delivery pages"]'));
    }

    public function testTheLogIsPagedFiftyToAPageAndTheOverviewShowsTheLatestTwentyFive(): void
    {
        $secret = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        for ($i = 0; $i < 53; ++$i) {
            [$status] = $this->hook('jira', $secret, ['webhookEvent' => 'jira:issue_created', 'issue' => ['key' => 'PG-'.$i]], $this->jiraId());
            self::assertSame(200, $status);
        }
        $subjects = static fn ($crawler): array => array_map(static fn (string $t): string => (string) (preg_match('/PG-\d+/', $t, $m) ? $m[0] : ''), $crawler->filter('[data-testid=delivery-row]')->each(static fn ($n): string => $n->text()));

        $first = $this->open('/admin/projects/blog/webhooks');
        self::assertCount(50, $first->filter('[data-testid=delivery-row]'));
        self::assertCount(0, $first->filter('nav[aria-label="Delivery pages"] a:contains("Newer")'));
        self::assertCount(1, $first->filter('nav[aria-label="Delivery pages"] a:contains("Older")'));
        $second = $this->open('/admin/projects/blog/webhooks?page=2');
        self::assertCount(3, $second->filter('[data-testid=delivery-row]'));
        self::assertCount(1, $second->filter('nav[aria-label="Delivery pages"] a:contains("Newer")'));
        self::assertCount(0, $second->filter('nav[aria-label="Delivery pages"] a:contains("Older")'));
        $seen = array_merge($subjects($first), $subjects($second));
        self::assertCount(53, array_unique($seen), 'no row on both pages and none lost');
        self::assertSame('PG-52', $seen[0], 'newest first');

        // The pager keeps a filter; a page out of range is the first page, or an empty one.
        $filtered = $this->open('/admin/projects/blog/webhooks?result=ignored&page=2');
        self::assertCount(3, $filtered->filter('[data-testid=delivery-row]'));
        self::assertStringContainsString('result=ignored', (string) $filtered->filter('nav[aria-label="Delivery pages"] a:contains("Newer")')->attr('href'));
        self::assertCount(50, $this->open('/admin/projects/blog/webhooks?page=0')->filter('[data-testid=delivery-row]'));
        self::assertCount(50, $this->open('/admin/projects/blog/webhooks?page=-4')->filter('[data-testid=delivery-row]'));
        $beyond = $this->open('/admin/projects/blog/webhooks?page=99');
        self::assertCount(0, $beyond->filter('[data-testid=delivery-row]'));
        self::assertCount(1, $beyond->filter('[data-testid=deliveries-empty]'));
        self::assertCount(1, $beyond->filter('nav[aria-label="Delivery pages"] a:contains("Newer")'));

        $overview = $this->open('/admin/webhooks');
        self::assertCount(25, $overview->filter('[data-testid=delivery-row]'));
        self::assertCount(0, $overview->filter('nav[aria-label="Delivery pages"]'));
    }

    public function testTheEmptyPagesShowNothingConfiguredAndTheSecretAddressIsNotReadable(): void
    {
        $crawler = $this->open('/admin/projects/blog/webhooks');
        self::assertCount(2, $crawler->filter('[data-testid=webhook-endpoint]'));
        self::assertSame('No delivery has arrived yet.', trim($crawler->filter('[data-testid=deliveries-empty]')->text()));
        self::assertCount(0, $crawler->filter('nav[aria-label="Delivery pages"]'));
        self::assertSame('Webhooks', trim($crawler->filter('nav[aria-label="Project sections"] a[aria-current=page]')->text()));
        self::assertStringContainsString('Webhooks · blog', $crawler->filter('title')->text());
        foreach (['jira', 'bitbucket'] as $source) {
            $card = $crawler->filter('[data-source='.$source.']');
            self::assertSame('No secret', trim($card->filter('[data-testid=webhook-secret-state]')->text()), $source);
            foreach (['webhook-rotated', 'webhook-rules', 'webhook-no-rules', 'webhook-token', 'webhook-latest'] as $absent) {
                self::assertCount(0, $card->filter('[data-testid='.$absent.']'), $source.' '.$absent);
            }
        }

        // Opening the secret address is not a way to see a secret.
        $this->open('/admin/projects/blog/webhooks/bitbucket/secret');
        self::assertResponseStatusCodeSame(404);
        $this->open('/admin/projects/blog/webhooks/gitlab/secret');
        self::assertResponseStatusCodeSame(404);
    }

    public function testACreatedSecretIsOnTheCardAsSetWithItsDateAndNoRulesYet(): void
    {
        $this->post('/admin/projects/blog/webhooks/bitbucket/secret', []);
        $once = $this->client->getCrawler();
        self::assertSame('Webhook secret', trim($once->filter('h1')->text()));
        self::assertStringContainsString('This is the only time it is shown.', $once->filter('[data-testid=webhook-secret-once]')->text());
        self::assertCount(1, $once->filter('[data-testid=webhook-secret-value][readonly]'));
        self::assertStringEndsWith('/hooks/bitbucket/blog', (string) $once->filter('#webhook-secret-url')->attr('value'));

        $card = $this->open('/admin/projects/blog/webhooks')->filter('[data-source=bitbucket]');
        self::assertSame('Secret set', trim($card->filter('[data-testid=webhook-secret-state]')->text()));
        self::assertMatchesRegularExpression('/Secret created or rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. It cannot be shown again\./', $card->filter('[data-testid=webhook-rotated]')->text());
        self::assertStringContainsString('No rules yet', $card->filter('[data-testid=webhook-no-rules]')->text());
        self::assertCount(0, $card->filter('[data-testid=webhook-create]'));
        self::assertStringContainsString('The old secret stops working at once.', $card->filter('[data-testid=webhook-rotate-details]')->text());
        self::assertSame('No secret', trim($this->open('/admin/projects/blog/webhooks')->filter('[data-source=jira] [data-testid=webhook-secret-state]')->text()));
    }

    public function testASecondCreateKeepsTheFirstSecretWorking(): void
    {
        $secret = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->post('/admin/projects/blog/webhooks/jira/secret', []);
        self::assertResponseRedirects();

        [$status] = $this->hook('jira', $secret, self::jiraMove('PW-2', 'Done'), $this->jiraId());
        self::assertSame(200, $status);
    }

    public function testRotationKillsTheOldSecretAndRendersAnotherOnce(): void
    {
        $first = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->post('/admin/projects/blog/webhooks/jira/secret', ['rotate' => '1']);
        $second = (string) $this->client->getCrawler()->filter('[data-testid=webhook-secret-value]')->attr('value');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $second);
        self::assertSame(401, $this->hook('jira', $first, self::jiraMove('PW-1', 'Done'), $this->jiraId())[0]);
        self::assertSame(200, $this->hook('jira', $second, self::jiraMove('PW-1', 'Done'), $this->jiraId())[0]);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function unprotectedPosts(): iterable
    {
        yield 'create' => ['/admin/projects/blog/webhooks/bitbucket/secret', []];
        yield 'rotate' => ['/admin/projects/blog/webhooks/jira/secret', ['rotate' => '1']];
        yield 'token without a token' => ['/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'abc']];
        yield 'token with a forged token' => ['/admin/projects/blog/webhooks/bitbucket/token', ['_token' => 'forged', 'token' => 'abc']];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('unprotectedPosts')]
    public function testEveryChangeNeedsTheCsrfTokenAndChangesNothing(string $path, array $parameters): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);

        $this->client->request('POST', self::BASE.$path, $parameters, [], $this->browserServer());

        self::assertResponseStatusCodeSame(403);
        $card = $this->open('/admin/projects/blog/webhooks')->filter('[data-source=bitbucket]');
        self::assertSame('No secret', trim($card->filter('[data-testid=webhook-secret-state]')->text()));
    }

    public function testAnUnknownProjectHasNoReceiversToChange(): void
    {
        $this->post('/admin/projects/nope/webhooks/jira/secret', []);
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/nope/webhooks/bitbucket/token', ['token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'blank' => ['   '];
        yield 'inner space' => ['two words'];
        yield 'non-ASCII' => ['tökën'];
    }

    #[DataProvider('badTokens')]
    public function testABadTokenIsRefusedWithItsMessageAndNeverEchoed(string $bad): void
    {
        $this->bitbucketSecret();

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => $bad]);
        $page = $this->client->followRedirect();

        self::assertStringContainsString('Enter the access token: printable characters without spaces, at most 2048.', $page->filter('[data-testid=flashes]')->text());
        if ('' !== trim($bad)) {
            self::assertStringNotContainsString($bad, $this->body());
        }
        self::assertFalse($this->endpoint()->hasToken());
        self::assertStringContainsString('No pipeline token: a run-pipeline rule starts nothing until one is set.', $page->filter('[data-testid=webhook-token-state]')->text());
        self::assertCount(0, $page->filter('[data-testid=webhook-token-clear]'));
    }

    public function testThePipelineTokenFormIsHiddenRequiredAndBoundedAndTheLongestTokenIsKept(): void
    {
        $this->bitbucketSecret();
        $crawler = $this->open('/admin/projects/blog/webhooks');
        $input = $crawler->filter('[data-source=bitbucket] [data-testid=webhook-token-input]');
        self::assertSame('password', $input->attr('type'));
        self::assertSame('2048', $input->attr('maxlength'));
        self::assertSame('off', $input->attr('autocomplete'));
        self::assertNotNull($input->attr('required'));
        self::assertStringContainsString('Set the pipeline token', $crawler->filter('[data-source=bitbucket]')->text());

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => str_repeat('A', 2048)]);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('The pipeline token is saved. It cannot be shown again.', $page->filter('[data-testid=flashes]')->text());
        self::assertMatchesRegularExpression('/Pipeline token set \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. It cannot be shown again\./', $page->filter('[data-testid=webhook-token-state]')->text());
        self::assertStringContainsString('Replace the pipeline token', $page->filter('[data-source=bitbucket]')->text());
        self::assertStringNotContainsString('AAAAAAAAAAAAAAAA', $this->body());

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['clear' => '1']);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('The pipeline token was removed.', $page->filter('[data-testid=flashes]')->text());
        self::assertCount(0, $page->filter('[data-testid=webhook-token-clear]'));
        self::assertCount(0, $page->filter('[data-source=jira] [data-testid=webhook-token]'));
        self::assertCount(1, $this->open('/admin/webhooks')->filter('[data-project=blog][data-source=bitbucket] [data-testid=webhook-token]'));
    }

    public function testTheCardsListTheRulesInOrderOnBothPagesUntilTheyAreRemoved(): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
        $dispatched = $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'For review'], ['template' => 'jira-work']);
        $this->service()->addRule('blog', WebhookSource::Bitbucket, 'run-pipeline', ['on_event' => 'pullrequest:approved', 'repo' => 'acme/blog'], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $this->service()->addRule('blog', WebhookSource::Bitbucket, 'deploy', ['on_event' => 'repo:push', 'repo' => 'acme/blog', 'branch' => 'feature/*-wip'], ['template' => 'jira-work', 'label_prefix' => 'fb', 'strip_suffix' => '-wip']);

        foreach (['/admin/projects/blog/webhooks', '/admin/webhooks'] as $path) {
            $crawler = $this->open($path);
            $jira = $crawler->filter('[data-project=blog][data-source=jira] [data-testid=webhook-rules] li');
            self::assertCount(2, $jira, $path);
            self::assertStringContainsString('on_status=Dispatched', $jira->eq(0)->text());
            self::assertStringContainsString('→ deploy', $jira->eq(0)->text());
            self::assertStringContainsString('jira-work', $jira->eq(0)->text());
            self::assertStringContainsString('on_status=For review', $jira->eq(1)->text());
            $bitbucket = $crawler->filter('[data-project=blog][data-source=bitbucket] [data-testid=webhook-rules] li');
            self::assertCount(2, $bitbucket, $path);
            self::assertStringContainsString('on_event=pullrequest:approved', $bitbucket->eq(0)->text());
            self::assertStringContainsString('→ run-pipeline', $bitbucket->eq(0)->text());
            self::assertStringContainsString('renovate-merge', $bitbucket->eq(0)->text());
            self::assertStringContainsString('branch=feature/*-wip', $bitbucket->eq(1)->text());
            self::assertCount(0, $crawler->filter('[data-project=blog][data-source=jira] [data-testid=webhook-no-rules]'));
        }

        $this->service()->removeRule('blog', (int) $dispatched->id());
        $jira = $this->open('/admin/projects/blog/webhooks')->filter('[data-source=jira] [data-testid=webhook-rules] li');
        self::assertCount(1, $jira);
        self::assertStringNotContainsString('Dispatched', $jira->text());
    }

    public function testTheProjectTabIsInTheTabStrip(): void
    {
        $crawler = $this->open('/admin/projects/blog');

        self::assertSame('Webhooks', trim($crawler->filter('nav[aria-label="Project sections"] a[href$="/webhooks"]')->text()));
    }

    private function bitbucketSecret(): void
    {
        $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
    }

    private function endpoint(): \App\Domain\Webhook\WebhookEndpoint
    {
        $this->em();
        $repository = $this->container()->get(\App\Domain\Webhook\WebhookRepository::class);
        self::assertInstanceOf(\App\Domain\Webhook\WebhookRepository::class, $repository);
        $endpoint = $repository->endpoint('blog', WebhookSource::Bitbucket);
        self::assertNotNull($endpoint);

        return $endpoint;
    }

    public function testTheTokenFormSetsReplacesAndRemovesTheTokenAndNeverShowsIt(): void
    {
        $this->bitbucketSecret();
        $crawler = $this->open('/admin/projects/blog/webhooks');
        self::assertCount(1, $crawler->filter('[data-testid=webhook-token-input]'), 'only the Bitbucket card has the form');
        self::assertStringContainsString('No pipeline token', $crawler->filter('[data-testid=webhook-token-state]')->text());

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-page-token-1']);
        self::assertResponseRedirects('/admin/projects/blog/webhooks');
        self::assertTrue($this->endpoint()->hasToken());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Pipeline token set', $crawler->filter('[data-testid=webhook-token-state]')->text());
        self::assertStringNotContainsString('ATCTT-page-token-1', $this->body());
        self::assertCount(1, $crawler->filter('[data-testid=webhook-token-clear]'));

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-page-token-2']);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('ATCTT-page-token', $this->body());
        self::assertStringNotContainsString('ATCTT-page-token', $crawler->html());

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['clear' => '1']);
        $this->client->followRedirect();
        self::assertFalse($this->endpoint()->hasToken());
        self::assertStringContainsString('No pipeline token', $this->client->getCrawler()->filter('[data-testid=webhook-token-state]')->text());
    }

    public function testAStaleRemoveTokenSubmitSaysTheTokenWasAlreadyRemoved(): void
    {
        $this->bitbucketSecret();

        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['clear' => '1']);
        $crawler = $this->client->followRedirect();

        self::assertFalse($this->endpoint()->hasToken());
        self::assertStringContainsString('already removed', $crawler->filter('[role=alert], [data-testid=flash]')->text(''));
        self::assertStringNotContainsString('token was removed.', $this->body());
    }

    public function testABadTokenOrAMissingBitbucketSecretIsAWarningAndStoresNothing(): void
    {
        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-x']);
        self::assertResponseRedirects();
        self::assertStringContainsString('create its secret first', $this->client->followRedirect()->filter('[role=alert], [data-testid=flash]')->text(''));

        $this->bitbucketSecret();
        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'has a space']);
        $this->client->followRedirect();
        self::assertFalse($this->endpoint()->hasToken());
        self::assertStringNotContainsString('has a space', $this->body());
    }

    public function testTheTokenEndpointIsAdminOnlyAndNeedsTheCsrfToken(): void
    {
        $this->bitbucketSecret();
        foreach (['member' => 403, 'none' => 401] as $role => $code) {
            $this->as($role);
            $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-nope']);
            self::assertResponseStatusCodeSame($code, $role);
        }
        $this->as('admin');
        $this->client->request('POST', self::BASE.'/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-nope'], server: $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/projects/nope/webhooks/bitbucket/token', ['token' => 'ATCTT-nope']);
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/blog/webhooks/bitbucket/token', ['token' => 'ATCTT-nope'], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->endpoint()->hasToken());
    }

    public function testDeliveriesCanBeFilteredBySourceAndOutcome(): void
    {
        $this->bitbucketSecret();
        $jira = $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        foreach (['Dispatched', 'Done'] as $i => $status) {
            $body = (string) json_encode(['webhookEvent' => 'jira:issue_updated', 'timestamp' => $i, 'issue' => ['key' => 'FLE-9'], 'changelog' => ['items' => [['field' => 'status', 'fromString' => 'To Do', 'toString' => $status]]]]);
            $this->client->request('POST', self::BASE.'/hooks/jira/blog', server: ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_HUB_SIGNATURE' => Signature::sign($body, $jira)], content: $body);
        }

        self::assertCount(2, $this->open('/admin/webhooks')->filter('[data-testid=delivery-row]'));
        self::assertCount(1, $this->open('/admin/webhooks?result=ignored')->filter('[data-testid=delivery-row][data-result=ignored]'));
        self::assertCount(0, $this->open('/admin/webhooks?source=bitbucket')->filter('[data-testid=delivery-row]'));
        self::assertCount(2, $this->open('/admin/projects/blog/webhooks?source=jira')->filter('[data-testid=delivery-row]'));
        $crawler = $this->open('/admin/projects/blog/webhooks?result=accepted');
        self::assertCount(1, $crawler->filter('[data-testid=delivery-row]'));
        self::assertSame('accepted', $crawler->filter('select[name=result] option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-testid=delivery-filter-clear]'));
        self::assertCount(2, $this->open('/admin/webhooks?result=bogus&source=bogus')->filter('[data-testid=delivery-row]'), 'unknown values are no filter');
    }
}
