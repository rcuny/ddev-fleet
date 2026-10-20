<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Export;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Schedule\ScheduleRepository;
use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\ImportItem;
use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Export\ProjectImporter;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\ExportFixtures;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectTransferTest extends KernelTestCase
{
    use ExportFixtures;
    use FleetDatabase;

    private const string PASSPHRASE = 'correct horse battery';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function exporter(): ProjectExporter
    {
        $exporter = $this->service(ProjectExporter::class);
        \assert($exporter instanceof ProjectExporter);

        return $exporter;
    }

    private function importer(): ProjectImporter
    {
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);

        return $importer;
    }

    /**
     * A second host: a new FLEET_HOME and a new database (and so a new secret key) in the same test.
     */
    private function switchToEmptyHost(): void
    {
        $this->tearDownFleetDatabase();
        $this->setUpFleetDatabase();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        $document = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encode(array $document): string
    {
        return ExportFormat::encode($document);
    }

    public function testExportCarriesEveryKindAndNoIdsTimestampsOrSecrets(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $document = $this->decode($text);

        self::assertSame(['format', 'version', 'project', 'ports', 'templates', 'webhooks', 'schedules'], array_keys($document));
        self::assertSame('fleet-project-export', $document['format']);
        self::assertSame(1, $document['version']);
        self::assertSame(['typesense'], $document['project']['ports']);
        self::assertSame(['jira-work', 'plain'], array_column($document['templates'], 'name'));
        self::assertSame(['bitbucket', 'jira'], array_column($document['webhooks'], 'source'));
        self::assertSame(['blog-nightly', 'blog-weekly'], array_column($document['schedules'], 'name'));
        self::assertSame('main', $document['schedules'][0]['instance']);
        self::assertFalse($document['schedules'][1]['enabled']);
        self::assertStringNotContainsString(self::SECRET_VALUE, $text);
        self::assertStringNotContainsString('"secrets"', $text);
        self::assertDoesNotMatchRegularExpression('/"(id|created_at|updated_at|ciphertext|nonce|token)"/', $text);
        self::assertStringEndsWith("}\n", $text);
    }

    public function testRoundTripIntoAnEmptyHostIsByteEqualAndTheSecondImportChangesNothing(): void
    {
        $this->seedBlog();
        $first = $this->exporter()->export('blog', null, 'admin');

        $this->switchToEmptyHost();
        $preview = $this->importer()->preview($first);
        self::assertFalse($preview->hasErrors(), implode(' | ', $preview->errors));
        foreach ($preview->items as $item) {
            self::assertSame(ImportItem::CREATE, $item->action, $item->kind.' '.$item->name);
        }
        $kinds = array_unique(array_map(static fn (ImportItem $item): string => $item->kind, $preview->items));
        sort($kinds);
        self::assertSame(['port', 'project', 'schedule', 'template', 'webhook'], $kinds);

        $before = $this->fingerprint();
        self::assertSame(0, $before['project']);

        $applied = $this->importer()->apply($first, null, 'admin');
        self::assertSame('blog', $applied->project);
        self::assertSame($first, $this->exporter()->export('blog', null, 'admin'));

        $again = $this->importer()->preview($first);
        self::assertFalse($again->hasErrors());
        self::assertFalse($again->changes());
        foreach ($again->items as $item) {
            self::assertSame(ImportItem::UNCHANGED, $item->action, $item->kind.' '.$item->name);
        }
        $counts = $this->fingerprint();
        $this->importer()->apply($first, null, 'admin');
        self::assertSame($counts, $this->fingerprint(), 'An import with nothing to change writes nothing.');
    }

    public function testTheImportedWebhookEndpointsGetTheirOwnSecretsAndSchedulesKeepTheirState(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $this->switchToEmptyHost();
        $this->importer()->apply($text, null, 'admin');

        $schedules = $this->service(ScheduleRepository::class);
        \assert($schedules instanceof ScheduleRepository);
        $weekly = $schedules->findByName('blog-weekly');
        self::assertNotNull($weekly);
        self::assertFalse($weekly->isEnabled());
        self::assertSame('blog--stage', $weekly->instanceId());
        self::assertTrue($schedules->findByName('blog-nightly')?->isEnabled());
        self::assertSame(2, $this->fingerprint()['webhook_endpoint']);
    }

    public function testSecretsAreSealedWithAPassphraseAndRoundTrip(): void
    {
        $this->seedBlog();
        $sealed = $this->exporter()->export('blog', self::PASSPHRASE, 'admin');
        self::assertStringNotContainsString(self::SECRET_VALUE, $sealed);
        self::assertStringNotContainsString(self::PASSPHRASE, $sealed);
        $block = $this->decode($sealed)['secrets'];
        self::assertSame(['kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext'], array_keys($block));

        $this->switchToEmptyHost();
        self::assertTrue($this->importer()->hasSealedSecrets($sealed));
        $preview = $this->importer()->preview($sealed, self::PASSPHRASE);
        self::assertFalse($preview->hasErrors(), implode(' | ', $preview->errors));
        $secretItems = array_values(array_filter($preview->items, static fn (ImportItem $item): bool => 'secret' === $item->kind));
        self::assertSame(['JIRA_TOKEN'], array_map(static fn (ImportItem $item): string => $item->name, $secretItems));
        self::assertStringNotContainsString(self::SECRET_VALUE, json_encode($preview->toArray(), \JSON_THROW_ON_ERROR));

        $this->importer()->apply($sealed, self::PASSPHRASE, 'admin');
        $store = $this->service(SecretStore::class);
        \assert($store instanceof SecretStore);
        self::assertSame(self::SECRET_VALUE, $store->getManaged('blog', 'JIRA_TOKEN'));
        // The sealed block differs from run to run, everything else is the same.
        $plain = $this->decode($this->exporter()->export('blog', null, 'admin'));
        unset($plain['secrets']);
        $original = $this->decode($sealed);
        unset($original['secrets']);
        self::assertSame($original, $plain);

        $audit = $this->service(AuditEntryRepository::class);
        \assert($audit instanceof AuditEntryRepository);
        $log = json_encode(array_map(static fn (object $entry): string => $entry->action().'|'.$entry->subject(), $audit->latest(100)), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('project.import|blog', $log);
        self::assertStringNotContainsString(self::SECRET_VALUE, $log);
        self::assertStringNotContainsString(self::PASSPHRASE, $log);
    }

    public function testWithoutThePassphraseEverythingElseIsImportedAndTheSecretsAreReportedSkipped(): void
    {
        $this->seedBlog();
        $sealed = $this->exporter()->export('blog', self::PASSPHRASE, 'admin');
        $this->switchToEmptyHost();

        $plan = $this->importer()->apply($sealed, null, 'admin');
        $skipped = array_values(array_filter($plan->items, static fn (ImportItem $item): bool => ImportItem::SKIPPED === $item->action));
        self::assertCount(1, $skipped);
        self::assertSame('secret', $skipped[0]->kind);
        self::assertNotSame([], $plan->warnings);
        $store = $this->service(SecretStore::class);
        \assert($store instanceof SecretStore);
        self::assertSame([], $store->listManaged('blog'));
        self::assertSame(2, $this->fingerprint()['template']);
    }

    public function testAWrongPassphraseIsACleanErrorAndWritesNothing(): void
    {
        $this->seedBlog();
        $sealed = $this->exporter()->export('blog', self::PASSPHRASE, 'admin');
        $this->switchToEmptyHost();
        $before = $this->fingerprint();

        $preview = $this->importer()->preview($sealed, 'not the passphrase');
        self::assertTrue($preview->hasErrors());
        self::assertSame(['The passphrase is wrong, or the sealed secrets are damaged.'], $preview->errors);
        try {
            $this->importer()->apply($sealed, 'not the passphrase', 'admin');
            self::fail('A wrong passphrase must refuse the import.');
        } catch (InvalidExport $e) {
            self::assertStringNotContainsString('not the passphrase', $e->getMessage());
        }
        self::assertSame($before, $this->fingerprint());
    }

    public function testAShortPassphraseIsRefusedAtExport(): void
    {
        $this->seedBlog();
        $this->expectException(InvalidExport::class);
        $this->exporter()->export('blog', 'short', 'admin');
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): (array<string, mixed>|string), string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'not json' => [static fn (): string => '{not json', 'not valid JSON'];
        yield 'a list' => [static fn (): string => '[1,2]', 'not a project export'];
        yield 'wrong format' => [static fn (array $d): array => ['format' => 'other'] + $d, 'not a project export'];
        yield 'a newer version' => [static fn (array $d): array => ['version' => 2] + $d, 'version 2'];
        yield 'an unknown version' => [static fn (array $d): array => ['version' => 0] + $d, 'not a known'];
        yield 'an unknown top-level key' => [static fn (array $d): array => $d + ['extra' => 1], 'extra: unknown key'];
        yield 'an unknown nested key' => [static function (array $d): array {
            $d['templates'][0]['extra'] = 1;

            return $d;
        }, 'templates[0].extra: unknown key'];
        yield 'a missing key' => [static function (array $d): array {
            unset($d['project']['git']);

            return $d;
        }, 'project.git: missing'];
        yield 'a wrong type' => [static function (array $d): array {
            $d['project']['typesense'] = 'yes';

            return $d;
        }, 'project.typesense: expected true or false'];
        yield 'oversize' => [static fn (): string => str_repeat(' ', ExportFormat::MAX_BYTES + 1), 'larger than 2 MiB'];
        yield 'a bad git url' => [static function (array $d): array {
            $d['project']['git'] = 'not a url';

            return $d;
        }, 'project.git'];
        yield 'a bad template command' => [static function (array $d): array {
            $d['templates'][0]['post_deploy']['exec'] = ["ddev x\0y"];

            return $d;
        }, 'templates[0].post_deploy'];
        yield 'a default template that is not in the file' => [static function (array $d): array {
            $d['project']['default_template'] = 'ghost';

            return $d;
        }, 'project.default_template'];
        yield 'a webhook rule for a missing template' => [static function (array $d): array {
            $d['webhooks'][1]['rules'][0]['params']['template'] = 'ghost';

            return $d;
        }, 'webhooks[1].rules[0].template'];
        yield 'a port used by the project but not described' => [static function (array $d): array {
            $d['ports'] = [];

            return $d;
        }, 'project.ports[0]'];
        yield 'a bad cron' => [static function (array $d): array {
            $d['schedules'][0]['cron'] = 'every day';

            return $d;
        }, 'schedules[0].cron'];
        yield 'a template listed twice' => [static function (array $d): array {
            $d['templates'][1]['name'] = 'jira-work';

            return $d;
        }, 'templates: an entry appears twice'];
    }

    /**
     * @param callable(array<string, mixed>): (array<string, mixed>|string) $mutate
     */
    #[DataProvider('refusedFiles')]
    public function testARefusedFileWritesNothing(callable $mutate, string $expected): void
    {
        $this->seedBlog();
        $document = $this->decode($this->exporter()->export('blog', null, 'admin'));
        $this->switchToEmptyHost();
        $mutated = $mutate($document);
        $text = \is_string($mutated) ? $mutated : $this->encode($mutated);
        $before = $this->fingerprint();

        $preview = $this->importer()->preview($text);
        self::assertTrue($preview->hasErrors());
        self::assertStringContainsString($expected, implode(' | ', $preview->errors));
        try {
            $this->importer()->apply($text, null, 'admin');
            self::fail('The file must be refused.');
        } catch (InvalidExport) {
        }
        self::assertSame($before, $this->fingerprint(), 'A refused import writes nothing, audit included.');
    }

    public function testAPortThatCollidesWithAHostPortIsAPreviewErrorAndNothingIsWritten(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $this->switchToEmptyHost();
        $ports = $this->service(NamedPortService::class);
        \assert($ports instanceof NamedPortService);
        $ports->create(new NamedPortInput('other', 9108, 8200));
        $before = $this->fingerprint();

        $preview = $this->importer()->preview($text);
        self::assertTrue($preview->hasErrors());
        self::assertStringContainsString('ports[0].public', implode(' | ', $preview->errors));
        $this->expectException(InvalidExport::class);
        try {
            $this->importer()->apply($text, null, 'admin');
        } finally {
            self::assertSame($before, $this->fingerprint());
        }
    }

    public function testAPortWithTheSameNameAndOtherNumbersIsRefused(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $this->switchToEmptyHost();
        $ports = $this->service(NamedPortService::class);
        \assert($ports instanceof NamedPortService);
        $ports->create(new NamedPortInput('typesense', 9200, 8200));

        $preview = $this->importer()->preview($text);
        self::assertStringContainsString('other numbers', implode(' | ', $preview->errors));
    }

    public function testANameCollisionIsAnUpdateAndLeavesWhatTheFileDoesNotMention(): void
    {
        $this->seedBlog();
        $document = $this->decode($this->exporter()->export('blog', null, 'admin'));
        $document['project']['git'] = 'https://git.example.com/acme/blog-new.git';
        $document['project']['default_template'] = 'plain';
        $document['templates'][0]['post_deploy']['exec'] = ['ddev composer install'];
        unset($document['templates'][1]);
        $document['templates'] = array_values($document['templates']);
        $document['webhooks'][0]['rules'] = [];
        $document['project']['default_template'] = 'plain';

        $templates = $this->service(TemplateService::class);
        \assert($templates instanceof TemplateService);
        $templates->create('blog', new TemplateInput('host-only'));
        $document['schedules'][0]['cron'] = '15 3 * * *';

        $plan = $this->importer()->preview($this->encode($document));
        self::assertFalse($plan->hasErrors(), implode(' | ', $plan->errors));
        $byKey = [];
        foreach ($plan->items as $item) {
            $byKey[$item->kind.':'.$item->name] = $item;
        }
        self::assertSame(ImportItem::UPDATE, $byKey['project:blog']->action);
        self::assertStringContainsString('git', (string) $byKey['project:blog']->note);
        self::assertStringContainsString('default_template', (string) $byKey['project:blog']->note);
        self::assertSame(ImportItem::UPDATE, $byKey['template:jira-work']->action);
        self::assertSame(ImportItem::UNCHANGED, $byKey['port:typesense']->action);
        self::assertSame(ImportItem::UPDATE, $byKey['webhook:Bitbucket webhook']->action);
        self::assertSame(ImportItem::UPDATE, $byKey['schedule:blog-nightly']->action);
        self::assertSame(ImportItem::UNCHANGED, $byKey['schedule:blog-weekly']->action);

        $this->importer()->apply($this->encode($document), null, 'admin');
        $projects = $this->service(ProjectService::class);
        \assert($projects instanceof ProjectService);
        self::assertSame('https://git.example.com/acme/blog-new.git', $projects->get('blog')->git());
        self::assertSame('plain', $projects->get('blog')->defaultTemplate());
        $names = array_map(static fn ($template): string => $template->name(), $templates->list('blog'));
        self::assertSame(['host-only', 'jira-work', 'plain'], $names, 'Templates the file does not mention stay.');
        $nightly = $this->service(ScheduleRepository::class);
        \assert($nightly instanceof ScheduleRepository);
        self::assertSame('15 3 * * *', $nightly->findByName('blog-nightly')?->cron());
    }

    public function testAScheduleOfAnotherProjectWithTheSameNameIsRefused(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $this->switchToEmptyHost();
        $schedules = $this->service(ScheduleService::class);
        \assert($schedules instanceof ScheduleService);
        $schedules->create(new ScheduleInput('blog-nightly', '0 1 * * *', 'UTC', 'redeploy', 'shop--main'), 'admin');

        $preview = $this->importer()->preview($text);
        self::assertStringContainsString('schedules[0].name', implode(' | ', $preview->errors));
        $before = $this->fingerprint();
        try {
            $this->importer()->apply($text, null, 'admin');
            self::fail('Refused.');
        } catch (InvalidExport) {
        }
        self::assertSame($before, $this->fingerprint());
    }

    public function testTheErrorsNeverRepeatAValueFromTheFile(): void
    {
        $this->seedBlog();
        $document = $this->decode($this->exporter()->export('blog', null, 'admin'));
        $this->switchToEmptyHost();
        $document['project']['git'] = 'ftp://hunter2-leak.example.com/x';
        $document['templates'][0]['drupal_env'] = 'bad value hunter2-leak';

        $preview = $this->importer()->preview($this->encode($document));
        self::assertTrue($preview->hasErrors());
        self::assertStringNotContainsString('hunter2-leak', implode(' | ', $preview->errors));
    }

    public function testAFailureMidWriteRollsEverythingBack(): void
    {
        $this->seedBlog();
        $text = $this->exporter()->export('blog', null, 'admin');
        $this->switchToEmptyHost();
        // Not a rule violation: the database itself refuses the first schedule, after the ports, project and templates were written.
        $this->entityManager()->getConnection()->executeStatement("CREATE TRIGGER refuse_schedule BEFORE INSERT ON schedule BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = $this->fingerprint();

        try {
            $this->importer()->apply($text, null, 'admin');
            self::fail('The write must fail.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('boom', $e->getMessage());
        }

        self::assertSame($before, $this->fingerprint(), 'The ports, the project and its templates were rolled back.');
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }
}
