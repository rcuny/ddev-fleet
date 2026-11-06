<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The audit log page (/admin/audit): the table, the newest-first order, the action filter, the entries each action
 * leaves, and the access rules. The browser spec keeps only the phone layout.
 */
final class AuditPageTest extends AdminTestCase
{
    private const string TOKEN = 'sk-ant-oat01-audit-unique-5521';
    private const string SMTP_PASSWORD = 'audit-smtp-unique-7731';

    protected function setUp(): void
    {
        parent::setUp();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->run(new ArrayInput(['command' => 'fleet:host:init', '--domain' => 'fleet.example.com']), new NullOutput());
    }

    /**
     * @return list<list<string>> [when, who, action, subject] of each row, in page order
     */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid=audit-row]')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    private function seedProject(string $name): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    public function testThePageItsDescriptionAndTheWayToAndFromTheSecretsPage(): void
    {
        $crawler = $this->open('/admin/secrets');
        $link = $crawler->selectLink('Audit log')->link();
        $crawler = $this->client->click($link);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::BASE.'/admin/audit', $this->client->getRequest()->getUri());
        self::assertStringContainsString('Audit log', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Audit log', trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('Who changed or revealed a secret, and when. Values are never recorded.', $this->body());

        $back = $crawler->filter('a[href="/admin/secrets"]');
        self::assertGreaterThan(0, $back->count());
        $this->open('/admin/secrets');
        self::assertResponseStatusCodeSame(200);
    }

    public function testTheEntriesOfASecretNewestFirstFourColumnsTheActorAUtcTimeAndNoValue(): void
    {
        $this->confirmPassword();
        $this->forgetReauthAudit();
        $this->post('/admin/secrets', ['name' => 'AUD_KEY', 'value' => 'first-value-aud-1']);
        $this->post('/admin/secrets', ['name' => 'AUD_KEY', 'value' => 'second-value-aud-2']);
        $this->post('/admin/secrets/AUD_KEY/reveal', []);
        $this->post('/admin/secrets/AUD_KEY/delete', []);

        $crawler = $this->open('/admin/audit');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['When (UTC)', 'Who', 'Action', 'Subject'], $crawler->filter('th')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('The latest 200 entries, newest first.', $this->body());

        $rows = $this->rows($crawler);
        self::assertCount(4, $rows);
        self::assertSame(['secret.delete', 'secret.reveal', 'secret.put', 'secret.put'], array_column($rows, 2));
        foreach ($rows as $row) {
            self::assertSame('dev-admin', $row[1]);
            self::assertSame('fleet/AUD_KEY', $row[3]);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row[0]);
        }
        $times = array_column($rows, 0);
        $sorted = $times;
        rsort($sorted);
        self::assertSame($sorted, $times);
        self::assertStringNotContainsString('first-value-aud-1', $this->body());
        self::assertStringNotContainsString('second-value-aud-2', $this->body());
    }

    public function testTheFilterListsTheRecordedActionsNarrowsTheListKeepsItsSelectionAndResets(): void
    {
        $this->confirmPassword();
        $this->forgetReauthAudit();
        $this->post('/admin/secrets', ['name' => 'FIL_KEY', 'value' => 'fil-value']);
        $this->post('/admin/secrets/FIL_KEY/reveal', []);

        $crawler = $this->open('/admin/audit');
        $options = $crawler->filter('select[name=action] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertSame('All actions', $options[0]);
        self::assertSame(['secret.put', 'secret.reveal'], \array_slice($options, 1));
        self::assertCount(0, $crawler->filter('select[name=action] option[selected]'));

        $crawler = $this->open('/admin/audit?action=secret.put');
        self::assertSame('secret.put', $crawler->filter('select[name=action] option[selected]')->attr('value'));
        self::assertSame([['secret.put']], array_unique(array_map(static fn (array $r): array => [$r[2]], $this->rows($crawler)), \SORT_REGULAR));
        self::assertCount(1, $this->rows($crawler));

        $crawler = $this->open('/admin/audit?action=secret.reveal');
        $rows = $this->rows($crawler);
        self::assertCount(1, $rows);
        self::assertSame('secret.reveal', $rows[0][2]);

        $crawler = $this->open('/admin/audit?action=');
        self::assertCount(0, $crawler->filter('select[name=action] option[selected]'));
        self::assertCount(2, $this->rows($crawler));
    }

    public function testAnActionThatIsNotInTheLogIsIgnoredAndAMalformedOneIsA400(): void
    {
        $this->post('/admin/secrets', ['name' => 'IGN_KEY', 'value' => 'ign-value']);

        $crawler = $this->open('/admin/audit?action=no.such.action');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('select[name=action] option[selected]'));
        self::assertCount(1, $this->rows($crawler));

        $this->open('/admin/audit?action[]=secret.put');
        self::assertResponseStatusCodeSame(400);

        $this->open('/admin/audit?action='.rawurlencode('"><script>window.__pwAudit=1</script>'));
        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('<script>window.__pwAudit', $this->body());
    }

    public function testAStoredClaudeTokenIsRecordedAsClaudeTokenRotateWithTheSubjectAndTheActorNeverTheToken(): void
    {
        $crawler = $this->open('/admin/host?tab=claude');
        $this->client->submit($crawler->selectButton('Store token')->form(['host_claude_token[token]' => self::TOKEN]));

        $crawler = $this->open('/admin/audit?action=claude.token.rotate');
        $rows = $this->rows($crawler);
        self::assertCount(1, $rows);
        self::assertSame(['dev-admin', 'claude.token.rotate', 'fleet/claude_oauth_token'], \array_slice($rows[0], 1));
        self::assertStringNotContainsString(self::TOKEN, $this->body());
    }

    public function testANotificationPasswordStoredFromTheHostPageIsRecordedAsASecretPutOfTheHostSecret(): void
    {
        $crawler = $this->open('/admin/host?tab=notifications');
        $this->client->submit($crawler->selectButton('Save')->form([
            'host_notification[smtpHost]' => 'smtp.example.com',
            'host_notification[smtpPort]' => '587',
            'host_notification[smtpUser]' => 'fleet',
            'host_notification[smtpPassword]' => self::SMTP_PASSWORD,
            'host_notification[mailFrom]' => 'fleet@example.com',
        ]));

        $crawler = $this->open('/admin/audit?action=secret.put');
        $rows = $this->rows($crawler);
        self::assertCount(1, $rows);
        self::assertSame(['dev-admin', 'secret.put', 'fleet/smtp_password'], \array_slice($rows[0], 1));
        self::assertStringNotContainsString(self::SMTP_PASSWORD, $this->body());
    }

    public function testAProjectSecretAndItsDeletionWithTheProjectAreRecordedUnderProjectNameSecret(): void
    {
        $this->confirmPassword();
        $this->forgetReauthAudit();
        $this->seedProject('blog');
        $this->post('/admin/projects/blog/secrets', ['name' => 'PAUD_KEY', 'value' => 'paud-value']);
        $this->post('/admin/projects/blog/secrets/PAUD_KEY/reveal', []);
        $this->post('/admin/projects/blog/secrets/PAUD_KEY/delete', []);

        $rows = $this->rows($this->open('/admin/audit'));
        self::assertCount(3, $rows);
        self::assertSame(['secret.delete', 'secret.reveal', 'secret.put'], array_column($rows, 2));
        self::assertSame(['project:blog/PAUD_KEY'], array_values(array_unique(array_column($rows, 3))));
        self::assertSame(['dev-admin'], array_values(array_unique(array_column($rows, 1))));
    }

    public function testAMemberAndAVisitorWithoutASessionCannotReadTheLog(): void
    {
        $this->post('/admin/secrets', ['name' => 'ACC_KEY', 'value' => 'acc-value']);

        $this->as('member');
        $this->open('/admin/audit');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('data-testid="audit-row"', $this->body());

        $this->as('none');
        $this->open('/admin/audit');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('data-testid="audit-row"', $this->body());
    }
}
