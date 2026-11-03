<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Audit\AuditEntryRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SecretPagesTest extends AdminTestCase
{
    private const string VALUE = 'tok-ABC123-never-in-html';

    private function makeProject(string $name = 'blog'): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    private function store(): SecretStore
    {
        $this->em();
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);

        return $store;
    }

    /**
     * @return list<array{string, string, string}> [actor, action, subject], newest first
     */
    private function audit(): array
    {
        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em())->latest(50),
        );
    }

    private function addAssetFile(string $project, string $name, string $content): void
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $dir = $paths->assetsDir($project);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        file_put_contents($dir.'/'.$name, $content);
    }

    /**
     * Saves a secret through the page, as a browser would.
     */
    private function save(string $path, string $name, string $value): void
    {
        $crawler = $this->open($path);
        $this->submit($crawler, 'Save secret', ['name' => $name, 'value' => $value]);
    }

    // --- fleet-wide page

    public function testFleetPageShowsAnEmptyStateAndTheForm(): void
    {
        $crawler = $this->open('/admin/secrets');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Secrets', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=secrets-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=secret-row]'));
        self::assertCount(1, $crawler->filter('form[data-testid=secret-form] input[name=name]'));
        self::assertCount(1, $crawler->filter('form[data-testid=secret-form] textarea[name=value]'));
        self::assertSame('', trim($crawler->filter('textarea[name=value]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid=host-secrets]'));
    }

    public function testSavingASecretListsItsNameOnly(): void
    {
        $this->save('/admin/secrets', 'JIRA_CLAUDE_TOKEN', self::VALUE);

        self::assertResponseRedirects('/admin/secrets', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('JIRA_CLAUDE_TOKEN', $crawler->filter('[data-testid=flashes]')->text());

        self::assertCount(1, $crawler->filter('[data-testid=secret-row]'));
        $row = $crawler->filter('[data-testid=secret-row]');
        self::assertSame('JIRA_CLAUDE_TOKEN', trim($row->filter('[data-testid=secret-name]')->text()));
        self::assertStringContainsString('[[jira-claude-token]]', $row->text());
        self::assertStringContainsString('set by', $row->text());
        self::assertStringNotContainsString(self::VALUE, $this->body());
        self::assertCount(0, $crawler->filter('[data-testid=reveal-dialog]'));

        self::assertSame(self::VALUE, $this->store()->getManaged(null, 'JIRA_CLAUDE_TOKEN'));
        self::assertSame(['secret.put', 'fleet/JIRA_CLAUDE_TOKEN'], \array_slice($this->audit()[0], 1));
        self::assertNotSame('', $this->audit()[0][0]);
    }

    public function testReplacingKeepsOneRowAndNeverPrefillsTheValue(): void
    {
        $this->save('/admin/secrets', 'API_KEY', 'first-value-123');
        $this->save('/admin/secrets', 'API_KEY', 'second-value-456');

        $crawler = $this->open('/admin/secrets');
        self::assertCount(1, $crawler->filter('[data-testid=secret-row]'));
        self::assertSame('second-value-456', $this->store()->getManaged(null, 'API_KEY'));
        self::assertStringNotContainsString('first-value-123', $this->body());
        self::assertStringNotContainsString('second-value-456', $this->body());

        $replace = $crawler->filter('[data-testid=secret-replace]')->attr('href');
        self::assertSame('/admin/secrets?name=API_KEY#set-secret', $replace);
        $crawler = $this->open('/admin/secrets?name=API_KEY');
        self::assertSame('API_KEY', $crawler->filter('input[name=name]')->attr('value'));
        self::assertSame('', trim($crawler->filter('textarea[name=value]')->text()));

        self::assertSame(['secret.put', 'secret.put'], array_column($this->audit(), 1));
    }

    public function testAnInvalidNameInTheReplaceLinkIsNotEchoed(): void
    {
        $crawler = $this->open('/admin/secrets?name='.rawurlencode('"><script>x</script>'));

        self::assertSame('', (string) $crawler->filter('input[name=name]')->attr('value'));
        self::assertStringNotContainsString('<script>x', $this->body());
    }

    /**
     * @return iterable<string, array{string, string, string, string}> name, value, field with the error, text of it
     */
    public static function refusedValues(): iterable
    {
        yield 'lowercase name' => ['jira_token', 'abc', 'name', 'capital letters'];
        yield 'name with a dash' => ['JIRA-TOKEN', 'abc', 'name', 'capital letters'];
        yield 'name starting with a digit' => ['1TOKEN', 'abc', 'name', 'capital letters'];
        yield 'name too long' => [str_repeat('A', 65), 'abc', 'name', 'at most 64'];
        yield 'built-in placeholder' => ['BRANCH', 'abc', 'name', 'built-in [[branch]]'];
        yield 'built-in with underscores' => ['INSTANCE_FQDN', 'abc', 'name', 'built-in [[instance-fqdn]]'];
        yield 'empty value' => ['API_KEY', '', 'value', 'Enter a value'];
        yield 'only a newline' => ['API_KEY', "\r\n", 'value', 'Enter a value'];
        yield 'double-quoted value' => ['API_KEY', '"abc"', 'value', 'without surrounding quotes'];
        yield 'single-quoted value' => ['API_KEY', "'abc'", 'value', 'without surrounding quotes'];
        yield 'oversized value' => ['API_KEY', str_repeat('x', 65537), 'value', 'at most 64 KiB'];
        yield 'binary value' => ['API_KEY', "ab\0cd", 'value', 'must be text'];
    }

    #[DataProvider('refusedValues')]
    public function testRefusedInputAnswers422WithoutEchoingTheValue(string $name, string $value, string $field, string $message): void
    {
        $crawler = $this->open('/admin/secrets');
        $this->client->submit($crawler->selectButton('Save secret')->form(['name' => $name, 'value' => $value]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->client->getCrawler()->filter('[data-testid=error-'.$field.']')->text());
        if (\strlen($value) >= 3 && "\0" !== $value[2]) {
            self::assertStringNotContainsString(trim($value, "\"'"), $this->body());
        }
        self::assertSame([], $this->store()->listManaged(null));
        self::assertSame([], $this->audit());
    }

    public function testBothErrorsAreShownAtOnceAndTheNameStaysFilled(): void
    {
        $crawler = $this->open('/admin/secrets');
        $this->client->submit($crawler->selectButton('Save secret')->form(['name' => 'bad name', 'value' => '']));

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('[data-testid=error-name]'));
        self::assertCount(1, $crawler->filter('[data-testid=error-value]'));
        self::assertSame('bad name', $crawler->filter('input[name=name]')->attr('value'));
        self::assertSame('true', $crawler->filter('input[name=name]')->attr('aria-invalid'));
    }

    public function testCrlfAndATrailingNewlineAreNormalised(): void
    {
        $this->save('/admin/secrets', 'PEM_KEY', "line1\r\nline2\r\n");

        self::assertSame("line1\nline2", $this->store()->getManaged(null, 'PEM_KEY'));
    }

    public function testNonStringFieldsAreRefusedNotACrash(): void
    {
        $this->post('/admin/secrets', ['name' => ['A'], 'value' => ['b']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->store()->listManaged(null));
    }

    public function testHostSecretsAreListedReadOnly(): void
    {
        $this->store()->putFleetWide('claude_oauth_token', 'host-claude-token-value', 'setup');

        $crawler = $this->open('/admin/secrets');

        self::assertCount(1, $crawler->filter('[data-testid=host-secret-row]'));
        self::assertStringContainsString('claude_oauth_token', $crawler->filter('[data-testid=host-secrets]')->text());
        self::assertCount(0, $crawler->filter('[data-testid=secret-row]'));
        self::assertStringNotContainsString('host-claude-token-value', $this->body());
        self::assertCount(0, $crawler->filter('[data-testid=host-secrets] button'));
    }

    // --- reveal

    public function testRevealShowsTheValueOnceInADialogAndIsAudited(): void
    {
        $this->save('/admin/secrets', 'API_KEY', self::VALUE);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString(self::VALUE, $this->body());

        $form = $crawler->filter('form[action="/admin/secrets/API_KEY/reveal"]');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[name=_token]'));
        $this->client->submit($form->selectButton('Reveal')->form());

        self::assertResponseStatusCodeSame(200);
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        $crawler = $this->client->getCrawler();
        self::assertSame(self::VALUE, trim($crawler->filter('[data-testid=revealed-value]')->text()));
        self::assertSame(1, substr_count($this->body(), self::VALUE));
        self::assertCount(1, $crawler->filter('[data-testid=reveal-dialog]'));

        self::assertSame('secret.reveal', $this->audit()[0][1]);
        self::assertSame('fleet/API_KEY', $this->audit()[0][2]);
        self::assertStringNotContainsString(self::VALUE, json_encode($this->audit(), \JSON_THROW_ON_ERROR));

        // A plain visit afterwards shows no value.
        $this->open('/admin/secrets');
        self::assertStringNotContainsString(self::VALUE, $this->body());
        self::assertStringNotContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheEleventhRevealInAMinuteIsRefusedAndAudited(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');
        for ($i = 0; $i < 10; ++$i) {
            $this->post('/admin/secrets/API_KEY/reveal', []);
            self::assertResponseStatusCodeSame(200);
        }

        $this->post('/admin/secrets/API_KEY/reveal', []);

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringNotContainsString(self::VALUE, $this->body());
        self::assertStringContainsString('Too many reveals', $this->body());
        self::assertSame('secret.reveal-refused', $this->audit()[0][1]);
        self::assertSame('fleet/API_KEY', $this->audit()[0][2]);
    }

    public function testRevealOfAnUnknownSecretIsA404AndNotAudited(): void
    {
        $this->post('/admin/secrets/NOPE/reveal', []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->audit());
    }

    public function testRevealWithABadTokenIsRefusedAndNotAudited(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');

        $this->post('/admin/secrets/API_KEY/reveal', [], [], 'cross-site');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(self::VALUE, $this->body());
        self::assertSame([], $this->audit());
    }

    public function testRevealByGetIsNotRouted(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');

        $this->open('/admin/secrets/API_KEY/reveal');

        // The admin fallback route answers any unrouted /admin path with a 404.
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::VALUE, $this->body());
    }

    public function testACorruptedSecretCannotBeRevealedAndSaysSo(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');
        $this->em()->getConnection()->executeStatement('UPDATE secret SET ciphertext = zeroblob(40) WHERE name = ?', ['API_KEY']);

        $this->post('/admin/secrets/API_KEY/reveal', []);

        self::assertResponseRedirects('/admin/secrets', 303);
        self::assertStringContainsString('could not be opened', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame([], $this->audit());
    }

    // --- delete

    public function testDeleteRemovesTheSecretAndIsAudited(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');
        $this->store()->putManaged(null, 'OTHER_KEY', 'other-value-1', 'admin');

        $crawler = $this->open('/admin/secrets');
        $form = $crawler->filter('form[action="/admin/secrets/API_KEY/delete"]');
        self::assertCount(1, $form);
        $this->client->submit($form->selectButton('Delete secret')->form());

        self::assertResponseRedirects('/admin/secrets', 303);
        self::assertSame(['OTHER_KEY'], array_map(static fn ($s): string => $s->name, $this->store()->listManaged(null)));
        self::assertSame('secret.delete', $this->audit()[0][1]);
        self::assertSame('fleet/API_KEY', $this->audit()[0][2]);
    }

    public function testDeleteTwiceIsA404(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');

        $this->post('/admin/secrets/API_KEY/delete', []);
        self::assertResponseRedirects('/admin/secrets', 303);

        $this->post('/admin/secrets/API_KEY/delete', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteAndPutRefuseABadCsrfToken(): void
    {
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');

        $this->post('/admin/secrets/API_KEY/delete', [], [], 'cross-site');
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/secrets', ['name' => 'NEW_KEY', 'value' => 'abc'], [], 'cross-site');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['API_KEY'], array_map(static fn ($s): string => $s->name, $this->store()->listManaged(null)));
    }

    // --- project tab

    public function testTheProjectHasASecretsTab(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');

        $tab = $crawler->filter('nav[aria-label="Project sections"] a')->reduce(static fn ($a): bool => 'Secrets' === trim($a->text()));
        self::assertCount(1, $tab);
        self::assertSame('/admin/projects/blog/secrets', $tab->attr('href'));

        $crawler = $this->open('/admin/projects/blog/secrets');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertSame('Secrets', trim($crawler->filter('nav[aria-label="Project sections"] [aria-current=page]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=secrets-empty]'));
        self::assertCount(1, $crawler->filter('[data-testid=secrets-no-assets]'));
    }

    public function testProjectSecretsAreScopedToTheProject(): void
    {
        $this->makeProject();
        $this->makeProject('shop');
        $this->store()->putManaged(null, 'FLEET_KEY', 'fleet-value-1', 'admin');

        $this->save('/admin/projects/blog/secrets', 'BLOG_KEY', self::VALUE);

        self::assertResponseRedirects('/admin/projects/blog/secrets', 303);
        self::assertSame(self::VALUE, $this->store()->getManaged('blog', 'BLOG_KEY'));
        self::assertNull($this->store()->getManaged(null, 'BLOG_KEY'));
        self::assertNull($this->store()->getManaged('shop', 'BLOG_KEY'));
        self::assertSame('project:blog/BLOG_KEY', $this->audit()[0][2]);

        $crawler = $this->open('/admin/projects/blog/secrets');
        self::assertSame(['BLOG_KEY'], $crawler->filter('[data-testid=secret-name]')->each(static fn ($n): string => trim($n->text())));
        self::assertStringNotContainsString(self::VALUE, $this->body());
        $crawler = $this->open('/admin/projects/shop/secrets');
        self::assertCount(0, $crawler->filter('[data-testid=secret-row]'));
        $crawler = $this->open('/admin/secrets');
        self::assertSame(['FLEET_KEY'], $crawler->filter('[data-testid=secret-name]')->each(static fn ($n): string => trim($n->text())));
    }

    public function testASecretNoAssetFileUsesIsFlagged(): void
    {
        $this->makeProject();
        $this->store()->putManaged('blog', 'USED_KEY', 'used-value-1', 'admin');
        $this->store()->putManaged('blog', 'LONELY_KEY', 'lonely-value-1', 'admin');
        $this->addAssetFile('blog', '.env', "USED=\"[[used-key]]\"\n");

        $crawler = $this->open('/admin/projects/blog/secrets');

        self::assertCount(0, $crawler->filter('[data-testid=secrets-no-assets]'));
        $flagged = $crawler->filter('[data-testid=secret-row]')->reduce(static fn ($row): bool => $row->filter('[data-testid=secret-no-placeholder]')->count() > 0);
        self::assertCount(1, $flagged);
        self::assertSame('LONELY_KEY', $flagged->attr('data-secret'));
        self::assertStringContainsString('[[lonely-key]]', $flagged->filter('[data-testid=secret-warning]')->text());
    }

    public function testNoWarningWhileTheProjectHasNoAssetFiles(): void
    {
        $this->makeProject();
        $this->store()->putManaged('blog', 'LONELY_KEY', 'lonely-value-1', 'admin');

        $crawler = $this->open('/admin/projects/blog/secrets');

        self::assertCount(0, $crawler->filter('[data-testid=secret-no-placeholder]'));
        self::assertStringContainsString('No asset files yet', $crawler->filter('[data-testid=secrets-no-assets]')->text());
    }

    public function testFleetWideSecretsAreNeverFlagged(): void
    {
        $this->store()->putManaged(null, 'LONELY_KEY', 'lonely-value-1', 'admin');

        $crawler = $this->open('/admin/secrets');

        self::assertCount(0, $crawler->filter('[data-testid=secret-no-placeholder]'));
    }

    public function testProjectRevealAndDeleteWork(): void
    {
        $this->makeProject();
        $this->store()->putManaged('blog', 'BLOG_KEY', self::VALUE, 'admin');

        $this->post('/admin/projects/blog/secrets/BLOG_KEY/reveal', []);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(self::VALUE, trim($this->client->getCrawler()->filter('[data-testid=revealed-value]')->text()));
        self::assertSame('project:blog/BLOG_KEY', $this->audit()[0][2]);

        $this->post('/admin/projects/blog/secrets/BLOG_KEY/delete', []);
        self::assertResponseRedirects('/admin/projects/blog/secrets', 303);
        self::assertSame([], $this->store()->listManaged('blog'));
    }

    public function testAProjectSecretCannotBeRevealedThroughAnotherProjectOrTheFleetRoute(): void
    {
        $this->makeProject();
        $this->makeProject('shop');
        $this->store()->putManaged('blog', 'BLOG_KEY', self::VALUE, 'admin');

        $this->post('/admin/projects/shop/secrets/BLOG_KEY/reveal', []);
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/secrets/BLOG_KEY/reveal', []);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::VALUE, $this->body());
    }

    public function testUnknownProjectIs404OnEveryProjectRoute(): void
    {
        $this->open('/admin/projects/nope/secrets');
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/nope/secrets', ['name' => 'A', 'value' => 'b']);
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/nope/secrets/A/delete', []);
        self::assertResponseStatusCodeSame(404);
        $this->post('/admin/projects/nope/secrets/A/reveal', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingTheProjectDeletesItsSecretsAndAuditsIt(): void
    {
        $this->makeProject();
        $this->store()->putManaged('blog', 'BLOG_KEY', self::VALUE, 'admin');
        $this->store()->putManaged(null, 'FLEET_KEY', 'fleet-value-1', 'admin');

        $this->post('/admin/projects/blog/delete', []);

        self::assertResponseRedirects('/admin/projects', 303);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM secret'));
        self::assertSame('FLEET_KEY', $this->em()->getConnection()->fetchOne('SELECT name FROM secret'));
        self::assertSame('secret.delete', $this->audit()[0][1]);
        self::assertSame('project:blog/BLOG_KEY', $this->audit()[0][2]);
    }

    public function testARefusedProjectDeleteKeepsItsSecrets(): void
    {
        $this->makeProject();
        $this->store()->putManaged('blog', 'BLOG_KEY', self::VALUE, 'admin');
        $this->guard()->set('blog', 1);

        $this->post('/admin/projects/blog/delete', []);

        self::assertSame(['BLOG_KEY'], array_map(static fn ($s): string => $s->name, $this->store()->listManaged('blog')));
        self::assertSame([], $this->audit());
    }

    // --- nobody but an admin

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'fleet list' => ['GET', '/admin/secrets'];
        yield 'fleet put' => ['POST', '/admin/secrets'];
        yield 'fleet delete' => ['POST', '/admin/secrets/API_KEY/delete'];
        yield 'fleet reveal' => ['POST', '/admin/secrets/API_KEY/reveal'];
        yield 'project list' => ['GET', '/admin/projects/blog/secrets'];
        yield 'project put' => ['POST', '/admin/projects/blog/secrets'];
        yield 'project delete' => ['POST', '/admin/projects/blog/secrets/API_KEY/delete'];
        yield 'project reveal' => ['POST', '/admin/projects/blog/secrets/API_KEY/reveal'];
        yield 'audit' => ['GET', '/admin/audit'];
    }

    #[DataProvider('routes')]
    public function testAMemberGets403AndAnonymousGets401(string $method, string $path): void
    {
        $this->makeProject();
        $this->store()->putManaged(null, 'API_KEY', self::VALUE, 'admin');
        $this->store()->putManaged('blog', 'API_KEY', self::VALUE, 'admin');

        foreach (['member' => 403, 'none' => 401] as $role => $status) {
            $this->as($role);
            $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token', 'name' => 'NEW_KEY', 'value' => 'abc'] : [], [], $this->browserServer());
            self::assertResponseStatusCodeSame($status, $role.' '.$method.' '.$path);
            self::assertStringNotContainsString(self::VALUE, $this->body());
        }
        self::assertSame([], $this->audit());
        self::assertCount(1, $this->store()->listManaged(null));
    }

    // --- the audit page

    public function testAuditPageListsEntriesAndFiltersByAction(): void
    {
        $crawler = $this->open('/admin/audit');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Audit log', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=audit-empty]'));

        $this->save('/admin/secrets', 'API_KEY', self::VALUE);
        $this->post('/admin/secrets/API_KEY/reveal', []);
        $this->post('/admin/secrets/API_KEY/delete', []);

        $crawler = $this->open('/admin/audit');
        $rows = $crawler->filter('[data-testid=audit-row]')->each(static fn ($r): string => preg_replace('/\s+/', ' ', trim($r->text())) ?? '');
        self::assertCount(3, $rows);
        self::assertStringContainsString('secret.delete', $rows[0]);
        self::assertStringContainsString('secret.reveal', $rows[1]);
        self::assertStringContainsString('secret.put', $rows[2]);
        self::assertStringContainsString('fleet/API_KEY', $rows[0]);
        self::assertStringNotContainsString(self::VALUE, $this->body());

        $crawler = $this->open('/admin/audit?action=secret.reveal');
        self::assertCount(1, $crawler->filter('[data-testid=audit-row]'));
        self::assertStringContainsString('secret.reveal', $crawler->filter('[data-testid=audit-row]')->text());

        $crawler = $this->open('/admin/audit?action='.rawurlencode('<b>x</b>'));
        self::assertCount(3, $crawler->filter('[data-testid=audit-row]'));
        self::assertStringNotContainsString('<b>x</b>', $this->body());
    }

    public function testAuditPageShowsAtMostTwoHundredEntries(): void
    {
        $connection = $this->em()->getConnection();
        for ($i = 0; $i < 205; ++$i) {
            $connection->executeStatement('INSERT INTO audit_entry (actor, action, subject, created_at) VALUES (?, ?, ?, ?)', ['alice', 'secret.put', 'fleet/K'.$i, '2026-10-19 10:00:00']);
        }

        $crawler = $this->open('/admin/audit');

        self::assertCount(200, $crawler->filter('[data-testid=audit-row]'));
        self::assertStringContainsString('fleet/K204', $crawler->filter('[data-testid=audit-row]')->first()->text());
    }
}
