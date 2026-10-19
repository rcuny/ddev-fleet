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
