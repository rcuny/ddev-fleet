<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\ProjectRepository;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The Import / Export pages case by case (FLE-162, test diet 5/7): the matrices that used to run in a browser
 * (import-export-coverage.spec.ts) now run as HTTP requests against the kernel. ProjectTransferTest keeps the
 * access, CSRF and happy-path checks; this class holds every refusal message, every preview row and the sealed
 * secret paths, read from the rendered page. The browser keeps the download, the file input and the phone layout.
 */
final class ProjectTransferMatrixTest extends AdminTestCase
{
    private const string PASSPHRASE = 'correct horse battery';
    private const string GIT = 'https://git.example.com/acme/blog.git';
    private const array CATALOGUE_PORT = ['name' => 'typesense', 'public' => 9108, 'router' => 8108];
    private const string WRONG = 'The passphrase is wrong, or the sealed secrets are damaged.';
    private const string SEALED_VALUE = 'pw-value-secret-9f2c';

    /**
     * @param array<string, mixed> $over whole sections replaced
     *
     * @return array<string, mixed>
     */
    private static function doc(string $name, array $over = []): array
    {
        return $over + [
            'format' => 'fleet-project-export',
            'version' => 1,
            'project' => self::project($name),
            'ports' => [],
            'templates' => [],
            'webhooks' => [],
            'schedules' => [],
        ];
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function project(string $name, array $over = []): array
    {
        return $over + [
            'name' => $name,
            'git' => self::GIT,
            'default_branch' => null,
            'default_template' => null,
            'additional_hostnames' => [],
            'ports' => [],
            'git_bot' => ['mode' => 'inherit', 'name' => null, 'email' => null],
            'issue_id_regexp' => null,
            'display_submodule_branch' => null,
            'typesense' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function template(string $name = 'main'): array
    {
        return ['name' => $name, 'drupal_env' => null, 'post_deploy' => ['exec' => ['echo hello'], 'tty1' => [], 'tty2' => []]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jiraRule(string $template = 'main', string $status = 'Dispatched'): array
    {
        return ['source' => 'jira', 'rules' => [['action' => 'deploy', 'match' => ['on_status' => $status], 'params' => ['template' => $template]]]];
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function schedule(string $name, array $over = []): array
    {
        return $over + ['name' => $name, 'cron' => '0 3 * * *', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => 'main', 'enabled' => true];
    }

    /**
     * @param array<string, mixed>|string $document
     */
    private static function json(array|string $document): string
    {
        return \is_string($document) ? $document : json_encode($document, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
    }

    private function upload(string $content, ?string $passphrase = null): Crawler
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return $this->post('/admin/import/preview', null === $passphrase ? [] : ['passphrase' => $passphrase], ['file' => new UploadedFile($path, 'test.fleet-project.json', 'application/json', null, true)]);
    }

    private function confirm(string $document, ?string $passphrase = null): Crawler
    {
        return $this->post('/admin/import/apply', ['document' => $document] + (null === $passphrase ? [] : ['passphrase' => $passphrase]));
    }

    private function exists(string $name): bool
    {
        $repository = $this->container()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $repository);
        $this->em();

        foreach ($repository->all() as $project) {
            if ($project->name() === $name) {
                return true;
            }
        }

        return false;
    }

    private function createProject(string $name): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput($name, self::GIT));
        $this->em();
    }

    /**
     * @return list<string>
     */
    private function problems(): array
    {
        return $this->client->getCrawler()->filter('[data-testid=import-error-item]')->each(static fn (Crawler $item): string => trim($item->text()));
    }

    /**
     * @return array<string, string> "kind name" => action
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->client->getCrawler()->filter('[data-testid=import-row]') as $node) {
            $row = new Crawler($node);
            $rows[trim($row->filter('td')->eq(0)->text()).' '.trim($row->filter('td')->eq(1)->text())] = (string) $row->attr('data-action');
        }

        return $rows;
    }

    /** The page text with entities decoded (flash quotes are escaped in the HTML). */
    private function text(): string
    {
        return html_entity_decode($this->body());
    }

    private function seedCataloguePort(): void
    {
        $ports = $this->container()->get(NamedPortService::class);
        self::assertInstanceOf(NamedPortService::class, $ports);
        $ports->create(new NamedPortInput('typesense', 9108, 8108));
    }

    private function counts(): string
    {
        return trim($this->client->getCrawler()->filter('[data-testid=import-counts]')->text());
    }

    private function tableText(): string
    {
        return $this->client->getCrawler()->filter('[data-testid=import-table]')->text();
    }

    private function assertRefusedPage(): void
    {
        $page = $this->client->getCrawler();
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $page->filter('[data-testid=import-confirm-form]'));
        self::assertCount(1, $page->filter('[data-testid=import-upload]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableFiles(): iterable
    {
        yield 'an empty file' => ['', 'The file is not valid JSON.'];
        yield 'text that is not JSON' => ['this is not json at all', 'The file is not valid JSON.'];
        yield 'a truncated export' => [substr(self::json(self::doc('whatever')), 0, 200), 'The file is not valid JSON.'];
        yield 'a binary file' => ["\xff\xfe\x00\x01\x80\x81", 'The file is not valid JSON.'];
        yield 'a JSON list' => ['[1, 2, 3]', 'The file is not a project export.'];
        yield 'a JSON string' => ['"fleet-project-export"', 'The file is not a project export.'];
        yield 'another JSON document' => [self::json(['hello' => 'world']), 'The file is not a project export (format must be "fleet-project-export").'];
        yield 'a document without a version' => [self::json(['format' => 'fleet-project-export']), 'The file has no integer version.'];
        yield 'a string version' => [self::json(['format' => 'fleet-project-export', 'version' => '1']), 'The file has no integer version.'];
        yield 'a newer version' => [self::json(['format' => 'fleet-project-export', 'version' => 2]), 'The file is version 2; this host reads version 1 at most. Update this host first.'];
        yield 'version zero' => [self::json(['format' => 'fleet-project-export', 'version' => 0]), 'Version 0 is not a known export version.'];
        yield 'a document nested deeper than the parser reads' => ['{"format":"fleet-project-export","version":1,"x":'.str_repeat('[', 40).str_repeat(']', 40).'}', 'The file is not valid JSON.'];
        yield 'a file over 2 MiB' => [self::json(self::doc('whatever')).str_repeat(' ', 2 * 1024 * 1024), 'larger than 2 MiB'];
    }

    #[DataProvider('unreadableFiles')]
    public function testAnUnreadableFileIsRefusedWithItsMessageAndNothingIsWritten(string $content, string $message): void
    {
        $this->upload($content);

        $this->assertRefusedPage();
        $shown = trim($this->client->getCrawler()->filter('[data-testid=import-error], [data-testid=import-error-item]')->first()->text());
        self::assertStringContainsString($message, $shown);
        self::assertSame([], $this->rows());
        self::assertFalse($this->exists('whatever'));
    }

    public function testARefusalIsAnAlertAndTheNextGoodFileGoesThrough(): void
    {
        $this->upload('nope');
        self::assertSame('alert', $this->client->getCrawler()->filter('[data-testid=import-errors], [data-testid=import-error]')->first()->attr('role'));

        $this->upload(self::json(self::doc('after-refusal')));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-errors]'));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-confirm]'));
        self::assertFalse($this->exists('after-refusal'));
    }

    public function testTheEmptyUploadPageDescribesTheFormAndNoFileIsRefused(): void
    {
        $crawler = $this->open('/admin/import');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Import a project', trim($crawler->filter('h1')->text()));
        $card = $crawler->filter('[data-testid=import-upload]')->text();
        self::assertStringContainsString('1. Upload the file', $card);
        self::assertStringContainsString('At most 2 MiB', $card);
        self::assertStringContainsString('Assets are not part of it', $card);
        self::assertSame('application/json,.json', $crawler->filter('[data-testid=import-file]')->attr('accept'));
        self::assertSame('password', $crawler->filter('[data-testid=import-passphrase]')->attr('type'));
        self::assertSame('Passphrase (only if the file holds sealed secrets)', trim($crawler->filter('label[for=import-passphrase]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid=import-preview-section]'));
        self::assertCount(0, $crawler->filter('[data-testid=import-error]'));
        self::assertSame('/admin/projects', $crawler->selectLink('Back to projects')->attr('href'));

        $this->post('/admin/import/preview', []);
        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertStringContainsString('Choose the export file to upload.', $page->filter('[data-testid=import-error]')->text());
        self::assertSame('alert', $page->filter('[data-testid=import-error]')->attr('role'));
        self::assertCount(0, $page->filter('[data-testid=import-preview-section]'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedDocuments(): iterable
    {
        $name = 'pw-refusal';
        $project = static fn (array $over): array => self::doc($name, ['project' => self::project($name, $over)]);
        $without = static function (string $section, ?string $key = null) use ($name): array {
            $d = self::doc($name);
            if (null === $key) {
                unset($d[$section]);
            } else {
                unset($d[$section][$key]);
            }

            return $d;
        };

        yield 'an unknown key' => [self::doc($name) + ['surprise' => 1], 'surprise: unknown key.'];
        yield 'an unknown project key' => [$project(['extra' => 'x']), 'project.extra: unknown key.'];
        yield 'a missing section' => [$without('templates'), 'templates: expected a list.'];
        yield 'a missing project key' => [$without('project', 'git'), 'project.git: missing.'];
        yield 'a wrong type' => [$project(['git' => 5]), 'project.git: expected a string.'];
        yield 'a project that is not an object' => [self::doc($name, ['project' => 'nope']), 'project: expected an object.'];
        yield 'a project port not described in ports' => [$project(['ports' => ['nowhere']]), 'project.ports[0]: the port is not described in ports.'];
        yield 'a name with two dashes' => [self::doc('pw--bad'), 'project.name: The name must not contain two dashes in a row'];
        yield 'an upper-case name' => [self::doc('PwBad'), 'project.name: Use lowercase letters, digits and single dashes only'];
        yield 'a reserved name' => [self::doc('new'), 'project.name: This name is reserved.'];
        yield 'a name over 40 characters' => [self::doc(str_repeat('a', 41)), 'project.name: The name must be at most 40 characters.'];
        yield 'a git URL with a scheme the host does not accept' => [$project(['git' => 'ftp://example.com/x.git']), 'project.git: Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.'];
        yield 'credentials in the git URL' => [$project(['git' => 'https://user:secret@example.com/x.git']), 'project.git: The URL contains a user name or token'];
        yield 'a default template the file does not have' => [$project(['default_template' => 'ghost']), 'project.default_template: this project has no template with this name.'];
        yield 'an invalid branch' => [$project(['default_branch' => 'bad branch']), 'project.default_branch:'];
        yield 'a git identity override without a name or email' => [$project(['git_bot' => ['mode' => 'override', 'name' => null, 'email' => null]]), 'project.git_bot_name: Enter a name, an email or both'];
        yield 'an unknown git identity mode' => [$project(['git_bot' => ['mode' => 'weird', 'name' => null, 'email' => null]]), 'project.git_bot.mode: unknown mode.'];
        yield 'a template listed twice' => [self::doc($name, ['templates' => [self::template(), self::template()]]), 'templates: an entry appears twice.'];
        yield 'an invalid template name' => [self::doc($name, ['templates' => [self::template('Bad Name')]]), 'templates[0].name:'];
        yield 'a webhook source that does not exist' => [self::doc($name, ['webhooks' => [['source' => 'gitlab', 'rules' => []]]]), 'webhooks[0].source: unknown source.'];
        yield 'a webhook rule naming a template the file does not have' => [self::doc($name, ['templates' => [self::template()], 'webhooks' => [self::jiraRule('ghost')]]), "webhooks[0].rules[0].template: Choose one of the project's templates."];
        yield 'a Jira rule that is not a deploy' => [self::doc($name, ['templates' => [self::template()], 'webhooks' => [['source' => 'jira', 'rules' => [['action' => 'run-pipeline', 'match' => ['on_status' => 'Done'], 'params' => ['template' => 'main']]]]]]), 'webhooks[0].rules[0].action: A Jira rule can only deploy.'];
        yield 'two Jira rules for one status' => [self::doc($name, ['templates' => [self::template()], 'webhooks' => [['source' => 'jira', 'rules' => [self::jiraRule()['rules'][0], self::jiraRule()['rules'][0]]]]]), 'webhooks[0].rules[1].on_status: There is already a rule for this status.'];
        yield 'an invalid cron expression' => [self::doc($name, ['schedules' => [self::schedule('pw-bad-cron', ['cron' => '99 99 * * *'])]]), 'schedules[0].cron: Not a valid cron expression'];
        yield 'an unknown timezone' => [self::doc($name, ['schedules' => [self::schedule('pw-bad-tz', ['timezone' => 'Mars/Base'])]]), 'schedules[0].timezone: Enter an IANA timezone'];
        yield 'an unknown schedule action' => [self::doc($name, ['schedules' => [self::schedule('pw-bad-action', ['action' => 'explode'])]]), 'schedules[0].action: Choose one of:'];
        yield 'a schedule without an instance' => [self::doc($name, ['schedules' => [self::schedule('pw-no-instance', ['instance' => null])]]), 'schedules[0].instance: Enter the id of the instance to redeploy.'];
        yield 'a schedule instance label that is not valid' => [self::doc($name, ['schedules' => [self::schedule('pw-bad-label', ['instance' => 'Bad Label'])]]), 'schedules[0].instance: not a valid instance label.'];
        yield 'a schedule name that is not valid' => [self::doc($name, ['schedules' => [self::schedule('Bad Name')]]), 'schedules[0].name:'];
        yield 'a catalogue port with other numbers than this host has' => [self::doc($name, ['ports' => [['public' => 9109] + self::CATALOGUE_PORT]]), 'ports[0]: this host already has a port with this name and other numbers'];
        yield 'a sealed block that is not an object' => [self::doc($name, ['secrets' => 'abc']), 'secrets: expected an object.'];
        yield 'a sealed block with an unknown key derivation' => [self::doc($name, ['secrets' => ['kdf' => 'md5', 'opslimit' => 3, 'memlimit' => 268435456, 'salt' => 'AAAA', 'nonce' => 'AAAA', 'ciphertext' => 'AAAA']]), 'secrets.kdf: unknown key derivation.'];
        yield 'a sealed block that is not base64' => [self::doc($name, ['secrets' => ['kdf' => 'argon2id13', 'opslimit' => 3, 'memlimit' => 268435456, 'salt' => '***', 'nonce' => 'AAAA', 'ciphertext' => 'AAAA']]), 'secrets.salt: not base64.'];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('refusedDocuments')]
    public function testEachRefusedDocumentShowsItsMessageAndOffersNothingToConfirm(array $document, string $message): void
    {
        $this->seedCataloguePort();
        $this->upload(self::json($document));

        $this->assertRefusedPage();
        $page = $this->client->getCrawler();
        $errors = $page->filter('[data-testid=import-errors]');
        self::assertCount(1, $errors);
        self::assertStringContainsString('The file cannot be imported', $errors->text());
        self::assertStringContainsString('Nothing was written.', $errors->text());
        self::assertSame('alert', $errors->attr('role'));
        self::assertStringContainsString('Upload another file', $page->filter('[data-testid=import-upload]')->text());
        self::assertNotEmpty(array_filter($this->problems(), static fn (string $problem): bool => str_contains($problem, $message)), $message.' not in '.implode(' | ', $this->problems()));

        // The same refusal at the confirm step: the whole file is checked again.
        $this->confirm(self::json($document));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-confirm-form]'));
    }

    public function testARefusedItemIsShownAsSkippedOnTheRefusedPreview(): void
    {
        $this->seedCataloguePort();
        $this->upload(self::json(self::doc('pw-refusal-item', ['ports' => [['router' => 8109] + self::CATALOGUE_PORT], 'schedules' => [self::schedule('pw-bad-cron-item', ['cron' => 'x'])]])));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('skipped', $this->rows()['port typesense'] ?? null);
        self::assertStringContainsString('skipped.', $this->counts());
        self::assertNotSame([], $this->problems());
    }

    public function testAnOverlongKeyAndAnOverlongStringAreRefusedWithoutEchoingThem(): void
    {
        $this->upload(self::json(self::doc('pw-wrap') + [str_repeat('k', 40) => 1, str_repeat('q', 300) => 2]));
        self::assertResponseStatusCodeSame(422);
        self::assertNotSame([], $this->problems());

        $this->upload(self::json(self::doc('pw-wrap', ['project' => self::project('pw-wrap', ['git' => 'https://git.example.com/'.str_repeat('a', 9000).'.git'])])));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('too long', $this->problems()[0] ?? '');
        self::assertStringNotContainsString(str_repeat('a', 200), $this->body());
    }

    public function testANewProjectShowsEveryKindOfRowAndConfirmingWritesThem(): void
    {
        $name = 'pwi';
        $document = self::json(self::doc($name, ['ports' => [self::CATALOGUE_PORT], 'templates' => [self::template()], 'webhooks' => [self::jiraRule()], 'schedules' => [self::schedule('pwi-nightly')]]));
        $this->seedCataloguePort();

        $this->upload($document);

        self::assertResponseStatusCodeSame(200);
        $page = $this->client->getCrawler();
        self::assertSame('Preview: pwi', trim($page->filter('#preview-heading')->text()));
        self::assertEquals(['project pwi' => 'create', 'template main' => 'create', 'port typesense' => 'unchanged', 'webhook Jira webhook' => 'create', 'schedule pwi-nightly' => 'create'], $this->rows());
        self::assertStringContainsString('public 9108, router 8108', $this->tableText());
        self::assertStringContainsString('Jira webhook', $this->tableText());
        self::assertStringContainsString('a new secret is issued on this host; rotate it on the project page to read it', $this->tableText());
        self::assertSame('4 create, 0 update, 1 unchanged, 0 skipped.', $this->counts());
        self::assertCount(0, $page->filter('[data-testid=import-warning]'));
        self::assertCount(0, $page->filter('[data-testid=confirm-passphrase]'));
        self::assertCount(0, $page->filter('[data-testid=import-upload]'));
        self::assertSame('/admin/import', $page->selectLink('Cancel')->attr('href'));
        self::assertFalse($this->exists($name), 'The preview writes nothing.');

        $this->confirm($document);
        self::assertResponseRedirects('/admin/projects/pwi', 303);
        $this->client->followRedirect();
        self::assertStringContainsString('Project "pwi" imported.', $this->text());
        self::assertTrue($this->exists($name));

        $this->open('/admin/schedules');
        self::assertStringContainsString('pwi-nightly', $this->body());
        self::assertStringContainsString('0 3 * * *', $this->body());
        self::assertStringContainsString('pwi--main', $this->body());
    }

    public function testADisabledScheduleInTheFileIsImportedPaused(): void
    {
        $this->confirm(self::json(self::doc('pwd', ['schedules' => [self::schedule('pwd-off', ['enabled' => false])]])));

        self::assertResponseRedirects('/admin/projects/pwd', 303);
        $crawler = $this->open('/admin/schedules');
        $row = $crawler->filter('[data-testid=schedule-row][data-name=pwd-off]');
        self::assertCount(1, $row);
        self::assertSame('Paused', trim($row->filter('[data-testid=schedule-state]')->text()));
    }

    public function testCancelIsALinkBackToAnEmptyUploadPageAndNothingIsCreated(): void
    {
        $this->upload(self::json(self::doc('pwc', ['templates' => [self::template()]])));
        self::assertSame('/admin/import', $this->client->getCrawler()->selectLink('Cancel')->attr('href'));

        $crawler = $this->open('/admin/import');

        self::assertCount(0, $crawler->filter('[data-testid=import-preview-section]'));
        self::assertFalse($this->exists('pwc'));
    }

    public function testAnExistingProjectShowsUpdatesAndASecondImportHasNothingToDo(): void
    {
        $name = 'pwu';
        $other = 'https://git.example.com/acme/other.git';
        $this->createProject($name);
        $changed = self::json(self::doc($name, ['project' => self::project($name, ['git' => $other]), 'templates' => [self::template()]]));

        $this->upload($changed);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['project pwu' => 'update', 'template main' => 'create'], $this->rows());
        self::assertStringContainsString('changed: git', $this->tableText());
        self::assertSame('1 create, 1 update, 0 unchanged, 0 skipped.', $this->counts());

        $this->confirm($changed);
        self::assertResponseRedirects('/admin/projects/pwu', 303);
        $this->open('/admin/projects/pwu/source');
        self::assertStringContainsString($other, $this->body());

        $this->upload($changed);
        $page = $this->client->getCrawler();
        self::assertStringContainsString('Everything in the file is already on this host: there is nothing to import.', $page->filter('[data-testid=import-nothing]')->text());
        self::assertCount(0, $page->filter('[data-testid=import-confirm-form]'));
        self::assertSame(['project pwu' => 'unchanged', 'template main' => 'unchanged'], $this->rows());
        self::assertSame('0 create, 0 update, 2 unchanged, 0 skipped.', $this->counts());
    }

    public function testADifferingTemplateIsReplacedAndOneTheFileDoesNotMentionStays(): void
    {
        $name = 'pwt';
        $this->confirm(self::json(self::doc($name, ['templates' => [self::template('main'), self::template('extra')]])));
        self::assertResponseRedirects('/admin/projects/pwt', 303);

        $edited = self::template('main');
        $edited['post_deploy']['exec'] = ['echo changed', 'echo twice'];
        $changed = self::json(self::doc($name, ['templates' => [$edited]]));
        $this->upload($changed);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('update', $this->rows()['template main'] ?? null);
        self::assertArrayNotHasKey('template extra', $this->rows());
        self::assertStringContainsString('changed: post_deploy', $this->tableText());

        $this->confirm($changed);
        $crawler = $this->open('/admin/projects/pwt/templates');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $crawler->filter('[data-testid=template-row]'));
        $main = $crawler->filter('[data-testid=template-row]')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'main'));
        self::assertSame('2', trim($main->filter('[data-testid=template-post-deploy]')->text()));
    }

    public function testAScheduleNameOfAnotherProjectIsRefusedAndTheOtherScheduleStays(): void
    {
        $this->confirm(self::json(self::doc('pwa', ['schedules' => [self::schedule('pwa-job')]])));
        self::assertResponseRedirects('/admin/projects/pwa', 303);

        $this->upload(self::json(self::doc('pwb', ['schedules' => [self::schedule('pwa-job')]])));

        $this->assertRefusedPage();
        self::assertStringContainsString('schedules[0].name: a schedule with this name exists on this host and does not belong to this project.', $this->problems()[0]);
        self::assertSame('skipped', $this->rows()['schedule pwa-job'] ?? null);
        self::assertFalse($this->exists('pwb'));
        $crawler = $this->open('/admin/schedules');
        self::assertCount(1, $crawler->filter('[data-testid=schedule-row][data-name=pwa-job]'));
    }

    public function testTheFileTextIsCheckedAgainAtTheConfirmStep(): void
    {
        $this->upload(self::json(self::doc('pwk')));
        self::assertSame(self::json(self::doc('pwk')), $this->client->getCrawler()->filter('[data-testid=import-confirm-form] input[name=document]')->attr('value'));

        $this->confirm(self::json(['format' => 'fleet-project-export', 'version' => 2]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('version 2', $this->client->getCrawler()->filter('[data-testid=import-errors]')->text());
        self::assertStringContainsString('The import was refused and nothing was written', $this->text());
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-confirm-form]'));

        $this->confirm(self::json(self::doc('pwv', ['project' => self::project('pwv', ['git' => 'ftp://example.com/x.git'])])));
        self::assertStringContainsString('project.git: Only https://', $this->problems()[0]);
        self::assertFalse($this->exists('pwv'));
        self::assertFalse($this->exists('pwk'));
    }

    public function testAnEmptyDocumentFieldIsNotReceived(): void
    {
        $this->confirm('');

        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertStringContainsString('The file was not received. Upload it again.', $page->filter('[data-testid=import-error]')->text());
        self::assertCount(1, $page->filter('[data-testid=import-upload]'));
    }

    public function testAWrongTokenIsRefusedOnTheExportAndTheApplyAndTheConfirmFormCarriesOne(): void
    {
        $this->createProject('pwz');
        $this->client->request('POST', self::BASE.'/admin/projects/pwz/export', ['_token' => '!bad!'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('fleet-project-export', $this->body());
        $this->client->request('POST', self::BASE.'/admin/import/apply', ['_token' => '!bad!', 'document' => self::json(self::doc('pwy'))], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->exists('pwy'));

        $this->upload(self::json(self::doc('pwy')));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-confirm-form] input[name=_token]'));
    }

    public function testGetOnThePostOnlyEndpointsFindsNothing(): void
    {
        foreach (['/admin/import/preview', '/admin/import/apply', '/admin/projects/pw-nothing/export'] as $path) {
            $this->open($path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testTheExportCardDescribesTheOptionalPassphrase(): void
    {
        $this->createProject('pwe');

        $crawler = $this->open('/admin/projects/pwe/source');

        $field = $crawler->filter('[data-testid=export-passphrase]');
        self::assertSame('password', $field->attr('type'));
        self::assertSame('8', $field->attr('minlength'));
        self::assertSame('new-password', $field->attr('autocomplete'));
        self::assertSame('', (string) $field->attr('value'));
        $card = $crawler->filter('[data-testid=export-project]')->text();
        self::assertStringContainsString('Passphrase for the secrets (optional)', $card);
        self::assertStringContainsString('Secrets are left out unless you give a passphrase to seal them.', $card);
    }

    public function testTheUnsealedExportHasTheDocumentedKeysAndTheProjectFields(): void
    {
        $this->createProject('pwe');

        $this->post('/admin/projects/pwe/export', []);

        $document = json_decode($this->body(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['format', 'version', 'project', 'ports', 'templates', 'webhooks', 'schedules'], array_keys($document));
        self::assertSame('pwe', $document['project']['name']);
        self::assertSame(self::GIT, $document['project']['git']);
        self::assertNull($document['project']['default_template']);
        self::assertFalse($document['project']['typesense']);
    }

    public function testASealedExportHasTheSealedBlockKeysAndNeitherNameNorValueNorPassphrase(): void
    {
        $this->confirmPassword();
        $this->createProject('pwsec');
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        $store->putManaged('pwsec', 'PW_SEALED_AB12', self::SEALED_VALUE, 'admin');

        $this->post('/admin/projects/pwsec/export', ['passphrase' => self::PASSPHRASE]);

        $document = json_decode($this->body(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext'], array_keys($document['secrets']));
        foreach ([self::SEALED_VALUE, 'PW_SEALED_AB12', self::PASSPHRASE] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->body());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badPassphrases(): iterable
    {
        yield 'too short (7 characters)' => ['abcdefg', 'The passphrase needs at least 8 characters.'];
        yield 'too long (1025 bytes)' => [str_repeat('x', 1025), 'The passphrase is limited to 1024 bytes.'];
    }

    #[DataProvider('badPassphrases')]
    public function testAnUnusablePassphraseIsRefusedWithAMessageAndNoDownload(string $passphrase, string $message): void
    {
        $this->confirmPassword();
        $this->createProject('pwr');

        $this->post('/admin/projects/pwr/export', ['passphrase' => $passphrase]);

        self::assertResponseRedirects('/admin/projects/pwr/source', 303);
        self::assertNull($this->client->getResponse()->headers->get('Content-Disposition'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString($message, $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('', (string) $crawler->filter('[data-testid=export-passphrase]')->attr('value'));
        self::assertStringNotContainsString($passphrase, $this->body());
    }

    public function testExactlyEightCharactersAreAccepted(): void
    {
        $this->confirmPassword();
        $this->createProject('pw8');

        $this->post('/admin/projects/pw8/export', ['passphrase' => '12345678']);

        self::assertResponseStatusCodeSame(200);
        $document = json_decode($this->body(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertNotEmpty($document['secrets']);
    }

    /**
     * A project "pwq" with one secret, exported sealed and removed again: the file from "another host".
     */
    private function sealedFile(): string
    {
        $this->createProject('pwq');
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        $store->putManaged('pwq', 'PW_SEAL_AB12', self::SEALED_VALUE, 'admin');
        $exporter = $this->container()->get(ProjectExporter::class);
        self::assertInstanceOf(ProjectExporter::class, $exporter);
        $text = $exporter->export('pwq', self::PASSPHRASE, 'admin');
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->delete('pwq', 'admin');
        $this->em();

        return $text;
    }

    public function testWithoutAPassphraseTheSealedSecretsAreSkippedAndTheyCanBeAddedAtTheConfirmStep(): void
    {
        $text = $this->sealedFile();

        $this->upload($text);
        self::assertResponseStatusCodeSame(200);
        $page = $this->client->getCrawler();
        self::assertSame('The file holds sealed secrets and no passphrase was given: they were not imported.', trim($page->filter('[data-testid=import-warning]')->text()));
        self::assertContains('skipped', $this->rows());
        self::assertStringContainsString('no passphrase given: the secrets are not imported', $this->tableText());
        self::assertSame('password', $page->filter('[data-testid=confirm-passphrase]')->attr('type'));
        self::assertSame('Passphrase of the sealed secrets (empty: leave the secrets out)', trim($page->filter('label[for=confirm-passphrase]')->text()));

        $this->confirm($text);
        self::assertResponseRedirects('/admin/projects/pwq', 303);
        $this->client->followRedirect();
        self::assertStringContainsString('Project "pwq" imported.', $this->text());
        self::assertStringContainsString('The file holds sealed secrets and no passphrase was given: they were not imported.', $this->text());
        $crawler = $this->open('/admin/projects/pwq/secrets');
        self::assertCount(0, $crawler->filter('[data-testid=secret-row]'));
        self::assertStringNotContainsString(self::SEALED_VALUE, $this->body());

        // The project exists now: with no passphrase there is nothing to confirm, the warning stays.
        $this->upload($text);
        $page = $this->client->getCrawler();
        self::assertCount(1, $page->filter('[data-testid=import-nothing]'));
        self::assertStringContainsString('no passphrase was given', $page->filter('[data-testid=import-warning]')->text());
        self::assertCount(0, $page->filter('[data-testid=import-confirm-form]'));

        // With the passphrase at the upload the secret is planned; it is applied only when typed again at confirm.
        $this->upload($text, self::PASSPHRASE);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=confirm-passphrase]'));
        $this->confirm($text, self::PASSPHRASE);
        self::assertResponseRedirects('/admin/projects/pwq', 303);
        $this->client->followRedirect();
        self::assertStringNotContainsString('no passphrase was given', $this->body());
        $crawler = $this->open('/admin/projects/pwq/keys');
        self::assertStringContainsString('PW_SEAL_AB12', $crawler->filter('[data-testid=secret-row]')->text());
        self::assertStringNotContainsString(self::SEALED_VALUE, $this->body());
    }

    public function testTheRightPassphraseListsTheSecretByNameOnlyAndASecondImportFindsItUnchanged(): void
    {
        $text = $this->sealedFile();

        $this->upload($text, self::PASSPHRASE);

        self::assertResponseStatusCodeSame(200);
        $page = $this->client->getCrawler();
        self::assertCount(0, $page->filter('[data-testid=import-warning]'));
        self::assertSame('create', $this->rows()['secret PW_SEAL_AB12'] ?? null);
        self::assertStringNotContainsString(self::SEALED_VALUE, $this->body());
        self::assertStringNotContainsString(self::PASSPHRASE, $this->body());
        self::assertSame('', (string) $page->filter('[data-testid=confirm-passphrase]')->attr('value'));

        $this->confirm($text, self::PASSPHRASE);
        self::assertResponseRedirects('/admin/projects/pwq', 303);
        $this->client->followRedirect();
        self::assertStringNotContainsString(self::PASSPHRASE, $this->body());

        $this->upload($text, self::PASSPHRASE);
        self::assertSame('unchanged', $this->rows()['secret PW_SEAL_AB12'] ?? null);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-nothing]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-confirm-form]'));
    }

    public function testAWrongPassphraseADamagedBlockAndAForeignBlockAreRefused(): void
    {
        $text = $this->sealedFile();
        $document = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        $this->upload($text, 'not the passphrase');
        $this->assertRefusedPage();
        self::assertSame([self::WRONG], $this->problems());
        self::assertFalse($this->exists('pwq'));

        // Shorter than the minimum is simply wrong too.
        $this->upload($text, 'abc');
        self::assertSame([self::WRONG], $this->problems());

        $ciphertext = base64_decode($document['secrets']['ciphertext'], true);
        self::assertIsString($ciphertext);
        $ciphertext[0] = \chr(\ord($ciphertext[0]) ^ 0xFF);
        $damaged = $document;
        $damaged['secrets']['ciphertext'] = base64_encode($ciphertext);
        $this->upload(self::json($damaged), self::PASSPHRASE);
        self::assertSame([self::WRONG], $this->problems());

        $foreign = $document;
        $foreign['project']['name'] = 'pwh';
        $this->upload(self::json($foreign), self::PASSPHRASE);
        self::assertSame(['secrets: the sealed block does not belong to this project file.'], $this->problems());
        self::assertFalse($this->exists('pwh'));
    }

    public function testAWrongPassphraseAtTheConfirmStepIsRefusedAsAWholeAndNothingIsWritten(): void
    {
        $text = $this->sealedFile();

        $this->upload($text);
        $this->confirm($text, 'wrong passphrase');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::WRONG], $this->problems());
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-confirm-form]'));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-upload]'));
        self::assertStringNotContainsString('wrong passphrase', $this->body());
        self::assertFalse($this->exists('pwq'));
    }
}
