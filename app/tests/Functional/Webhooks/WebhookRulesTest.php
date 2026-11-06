<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhooks;

use App\Domain\Webhook\WebhookSource;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Webhooks\WebhookService;
use App\Tests\Integration\Command\Webhook\WebhookCommandsTest;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProviderExternal;

/**
 * The rules of the Integrations tab: what the form accepts or refuses is what `fleet:webhook:rule:add` accepts or
 * refuses, because both go through RuleInput and WebhookService::addRule().
 */
final class WebhookRulesTest extends AdminTestCase
{
    private const string FLASH = '[role=alert], [data-testid=flash]';

    protected function setUp(): void
    {
        parent::setUp();
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', defaultBranch: 'develop'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('jira-work'));
        $this->service()->issueSecret('blog', WebhookSource::Jira, false);
        $this->service()->issueSecret('blog', WebhookSource::Bitbucket, false);
    }

    private function service(): WebhookService
    {
        $service = $this->container()->get(WebhookService::class);
        self::assertInstanceOf(WebhookService::class, $service);

        return $service;
    }

    private function ruleCount(WebhookSource $source): int
    {
        $count = 0;
        foreach ($this->container()->get(\App\Domain\Webhook\WebhookRepository::class)->endpoints() as $endpoint) {
            if ($endpoint->source() === $source) {
                $count += \count($endpoint->rules());
            }
        }

        return $count;
    }

    /**
     * @param array<string, string|bool> $options the options of fleet:webhook:rule:add
     *
     * @return array<string, mixed> the same input as form fields
     */
    private static function fields(array $options): array
    {
        $fields = [];
        foreach ($options as $option => $value) {
            $fields[substr($option, 2)] = true === $value ? '1' : $value;
        }

        return $fields;
    }

    /**
     * @param array<string, string|bool> $options
     */
    #[DataProviderExternal(WebhookCommandsTest::class, 'ruleRefusals')]
    public function testTheFormRefusesWhatTheCommandRefusesWithTheSameMessage(array $options, string $message): void
    {
        $fields = self::fields($options);
        $source = $fields['source'] ?? 'jira';
        unset($fields['source']);
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $before = $this->ruleCount(WebhookSource::Jira) + $this->ruleCount(WebhookSource::Bitbucket);

        $this->post('/admin/projects/blog/webhooks/'.$source.'/rules', ['rule' => $fields]);

        self::assertResponseRedirects('/admin/projects/blog/integrations');
        $flash = $this->client->followRedirect()->filter(self::FLASH)->text('');
        self::assertStringContainsString('The rule was refused', $flash);
        self::assertStringContainsString($message, $flash);
        self::assertSame($before, $this->ruleCount(WebhookSource::Jira) + $this->ruleCount(WebhookSource::Bitbucket), 'a refusal adds nothing');
    }

    public function testTheFormAddsJiraAndBitbucketRulesIncludingReuseAndLabelPrefix(): void
    {
        $this->post('/admin/projects/blog/webhooks/jira/rules', ['rule' => ['action' => 'deploy', 'on-status' => 'Dispatched', 'template' => 'jira-work', 'branch' => '', 'label' => '']]);
        self::assertResponseRedirects('/admin/projects/blog/integrations');
        self::assertStringContainsString('added', $this->client->followRedirect()->filter(self::FLASH)->text(''));

        $this->post('/admin/projects/blog/webhooks/bitbucket/rules', ['rule' => ['action' => 'deploy', 'on-event' => 'repo:push', 'repo' => 'acme/blog', 'match-branch' => 'feature/*', 'template' => 'jira-work', 'label-prefix' => 'ci-', 'reuse' => '1']]);
        $this->client->followRedirect();
        $this->post('/admin/projects/blog/webhooks/bitbucket/rules', ['rule' => ['action' => 'deploy', 'on-event' => 'repo:push', 'repo' => 'acme/blog', 'match-branch' => '*-wip', 'template' => 'jira-work', 'label-prefix' => 'fb', 'strip-suffix' => '-wip']]);
        $this->client->followRedirect();
        $this->post('/admin/projects/blog/webhooks/bitbucket/rules', ['rule' => ['action' => 'run-pipeline', 'on-event' => 'pullrequest:approved', 'repo' => 'acme/blog', 'pattern' => 'renovate-merge', 'ref' => 'develop']]);
        $crawler = $this->client->followRedirect();

        self::assertSame(1, $this->ruleCount(WebhookSource::Jira));
        self::assertSame(3, $this->ruleCount(WebhookSource::Bitbucket));
        $rules = $crawler->filter('[data-source=bitbucket] [data-testid=webhook-rules] li');
        self::assertStringContainsString('reuse=1', $rules->eq(0)->text());
        self::assertStringContainsString('label_prefix=ci-', $rules->eq(0)->text());
        self::assertStringContainsString('renovate-merge', $rules->eq(2)->text());
    }

    public function testTheTabOffersTheAddFormWithTheSourceFieldsAndARemoveDialogPerRule(): void
    {
        $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $crawler = $this->open('/admin/projects/blog/integrations');

        $jira = $crawler->filter('[data-source=jira]');
        self::assertCount(1, $jira->filter('[data-testid=webhook-rule-form]'));
        self::assertCount(1, $jira->filter('input[name="rule[on-status]"]'));
        self::assertCount(0, $jira->filter('input[name="rule[on-event]"]'));
        self::assertCount(1, $jira->filter('[data-testid=webhook-rule-remove]'));
        $bitbucket = $crawler->filter('[data-source=bitbucket]');
        foreach (['on-event', 'repo', 'match-branch', 'state', 'comment', 'label', 'label-prefix', 'strip-suffix', 'reuse', 'pattern', 'ref'] as $field) {
            self::assertCount(1, $bitbucket->filter('[name="rule['.$field.']"]'), $field);
        }
        self::assertCount(0, $crawler->filter('details [data-testid=webhook-rotate]'), 'rotate asks in a dialog, not a <details>');
        self::assertCount(0, $crawler->filter('details [data-testid=webhook-token-clear-confirm]'));
        // The overview of every project stays read-only.
        self::assertCount(0, $this->open('/admin/webhooks')->filter('[data-testid=webhook-rule-form], [data-testid=webhook-rule-remove]'));
    }

    public function testARuleIsRemovedAndAStaleRemoveSaysSo(): void
    {
        $rule = $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $id = (int) $rule->id();

        $this->post('/admin/projects/blog/webhooks/rules/'.$id.'/remove', []);
        self::assertResponseRedirects('/admin/projects/blog/integrations');
        self::assertStringContainsString('removed', $this->client->followRedirect()->filter(self::FLASH)->text(''));
        self::assertSame(0, $this->ruleCount(WebhookSource::Jira));

        $this->post('/admin/projects/blog/webhooks/rules/'.$id.'/remove', []);
        self::assertStringContainsString('already removed', $this->client->followRedirect()->filter(self::FLASH)->text(''));
    }

    public function testAPostNeedsTheCsrfTokenAndChangesNothing(): void
    {
        $rule = $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);

        $this->client->request('POST', self::BASE.'/admin/projects/blog/webhooks/jira/rules', ['rule' => ['on-status' => 'Done', 'template' => 'jira-work']], [], $this->browserServer('same-origin'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.'/admin/projects/blog/webhooks/rules/'.$rule->id().'/remove', [], [], $this->browserServer('same-origin'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->ruleCount(WebhookSource::Jira));
    }

    public function testARuleOfAnotherProjectCannotBeRemovedThroughThisOne(): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        $rule = $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);

        $this->post('/admin/projects/shop/webhooks/rules/'.$rule->id().'/remove', []);

        self::assertStringContainsString('already removed', $this->client->followRedirect()->filter(self::FLASH)->text(''));
        self::assertSame(1, $this->ruleCount(WebhookSource::Jira));
    }

    public function testEveryChangeIsAuditedWithItsActorAndNoRuleValue(): void
    {
        $this->post('/admin/projects/blog/webhooks/bitbucket/rules', ['rule' => ['action' => 'deploy', 'on-event' => 'repo:push', 'repo' => 'acme/blog', 'match-branch' => 'secret-branch-*', 'template' => 'jira-work']]);
        $id = $this->container()->get(\App\Domain\Webhook\WebhookRepository::class)->endpoint('blog', WebhookSource::Bitbucket)?->rules()[0]->id();
        $this->post('/admin/projects/blog/webhooks/rules/'.$id.'/remove', []);

        $audit = $this->container()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);
        $added = $audit->latest(10, AuditLog::WEBHOOK_RULE_ADD);
        $removed = $audit->latest(10, AuditLog::WEBHOOK_RULE_REMOVE);
        self::assertCount(1, $added);
        self::assertCount(1, $removed);
        self::assertSame(\sprintf('blog/bitbucket rule %d (deploy)', $id), $added[0]->subject());
        self::assertSame(\sprintf('blog/bitbucket rule %d', $id), $removed[0]->subject());
        self::assertNotSame(AuditLog::CLI_ACTOR, $added[0]->actor());
        self::assertStringNotContainsString('secret-branch', $added[0]->subject());
    }

    public function testTheCommandAuditsAsCli(): void
    {
        $rule = $this->service()->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $this->service()->removeRule('blog', (int) $rule->id());

        $audit = $this->container()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame(AuditLog::CLI_ACTOR, $audit->latest(10, AuditLog::WEBHOOK_RULE_ADD)[0]->actor());
        self::assertSame(AuditLog::CLI_ACTOR, $audit->latest(10, AuditLog::WEBHOOK_RULE_REMOVE)[0]->actor());
    }

    public function testTheDeliveryLogIsCollapsedUnderItsOwnHeadingUntilAFilterIsUsed(): void
    {
        $crawler = $this->open('/admin/projects/blog/integrations');
        self::assertSame('Delivery log', trim($crawler->filter('[data-testid=webhook-log] > h2')->text()));
        self::assertNull($crawler->filter('[data-testid=webhook-log-details]')->attr('open'));

        $crawler = $this->open('/admin/projects/blog/integrations?hide-ignored=1');
        self::assertNotNull($crawler->filter('[data-testid=webhook-log-details]')->attr('open'));
    }
}
