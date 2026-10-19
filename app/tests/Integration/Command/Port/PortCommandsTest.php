<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Port;

use App\Domain\Port\NamedPortRepository;
use App\Fleet\PortMirror\TraefikDirectory;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\FakeLiveEntrypoints;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PortCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateListDelete(): void
    {
        $created = $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108', '--json' => true]);

        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertSame('', $created->getErrorOutput());
        self::assertSame(['name' => 'typesense', 'public' => 9108, 'router' => 8108, 'projects' => []], $this->json($created));

        $list = $this->cli('fleet:port:list');
        self::assertSame(0, $list->getStatusCode());
        self::assertMatchesRegularExpression('#\btypesense 9108 8108 -#', $this->squash($list->getDisplay()));
        self::assertSame([['name' => 'typesense', 'public' => 9108, 'router' => 8108, 'projects' => []]], $this->json($this->cli('fleet:port:list', ['--json' => true])));

        $deleted = $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode(), $deleted->getErrorOutput());
        self::assertSame(['deleted' => 'typesense'], $this->json($deleted));
        self::assertSame([], $this->json($this->cli('fleet:port:list', ['--json' => true])));
        self::assertSame(3, $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true])->getStatusCode());
    }

    public function testReservedPortExits1(): void
    {
        $reserved = $this->cli('fleet:port:create', ['name' => 'web', '--public' => '443', '--router' => '8108']);

        self::assertSame(1, $reserved->getStatusCode());
        self::assertStringStartsWith('--public: ', $reserved->getErrorOutput());
        self::assertSame([], self::getContainer()->get(NamedPortRepository::class)->all());
    }

    public function testBadInputNamesTheArgumentAndOptions(): void
    {
        $bad = $this->cli('fleet:port:create', ['name' => 'Bad Name', '--public' => 'http', '--router' => '8080']);
        $missing = $this->cli('fleet:port:create', ['name' => 'typesense', '--json' => true]);

        self::assertSame(1, $bad->getStatusCode());
        $keys = array_map(static fn (string $line): string => explode(': ', $line, 2)[0], explode("\n", trim($bad->getErrorOutput())));
        self::assertSame(['<name>', '--public', '--router'], $keys);
        self::assertSame(1, $missing->getStatusCode());
        self::assertSame(['--public', '--router'], array_keys($this->json($missing)['fields']));
    }

    public function testDuplicateIsAFieldError(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);

        $again = $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9109', '--router' => '8108']);

        self::assertSame(1, $again->getStatusCode());
        self::assertSame(['<name>', '--router'], array_map(static fn (string $line): string => explode(': ', $line, 2)[0], explode("\n", trim($again->getErrorOutput()))));
    }

    public function testDeleteUsedPortExits2(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $this->cli('fleet:project:create', ['name' => 'blog', '--git' => 'https://git.example.com/acme/blog.git', '--port' => ['typesense']]);

        $refused = $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true]);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('Used by project(s): blog', $refused->getErrorOutput());
        self::assertCount(1, self::getContainer()->get(NamedPortRepository::class)->all());

        // The same answer without --yes: nobody is asked to confirm what cannot happen.
        $asked = $this->cli('fleet:port:delete', ['name' => 'typesense'], [], interactive: true);
        self::assertSame(2, $asked->getStatusCode());
        self::assertStringContainsString('Used by project(s): blog', $asked->getErrorOutput());
    }

    public function testDeleteNeedsYes(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);

        $refused = $this->cli('fleet:port:delete', ['name' => 'typesense']);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('--yes', $refused->getErrorOutput());
        self::assertCount(1, self::getContainer()->get(NamedPortRepository::class)->all());
    }

    public function testProjectCreateWithPortAttachesIt(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);

        $project = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => 'https://git.example.com/acme/blog.git', '--port' => ['typesense'], '--json' => true]);

        self::assertSame(0, $project->getStatusCode(), $project->getErrorOutput());
        self::assertSame(['typesense'], $this->json($project)['ports']);
        $list = $this->json($this->cli('fleet:port:list', ['--json' => true]));
        self::assertSame(['blog'], $list[0]['projects']);
        self::assertMatchesRegularExpression('#\btypesense 9108 8108 blog\b#', $this->squash($this->cli('fleet:port:list')->getDisplay()));
    }

    public function testProjectCreateWithAnUnknownPortIsRefused(): void
    {
        $bad = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => 'https://git.example.com/acme/blog.git', '--port' => ['nope']]);

        self::assertSame(1, $bad->getStatusCode());
        self::assertStringStartsWith('--port: Unknown port(s): nope.', $bad->getErrorOutput());
    }

    public function testRefreshWithNoInstancesRewritesNothing(): void
    {
        $json = $this->cli('fleet:port:refresh', ['--json' => true]);
        self::assertSame(0, $json->getStatusCode(), $json->getErrorOutput());
        $document = $this->json($json);
        self::assertSame(0, $document['rewritten']);
        self::assertSame([], $document['failed']);
        // No ~/.ddev/traefik in the test home: nothing to mirror, so nothing pending.
        self::assertSame('live', $document['mirror']['state']);
        self::assertSame([], $document['mirror']['mirrored']);
        self::assertStringContainsString('does not exist', $document['mirror']['skipped'][0]);

        $human = $this->cli('fleet:port:refresh');
        self::assertSame(0, $human->getStatusCode());
        self::assertStringContainsString('0 Caddy site(s) rewritten.', $human->getDisplay());
        self::assertStringContainsString('router mirror: live', $human->getDisplay());
    }

    private function traefik(): TraefikDirectory
    {
        $traefik = self::getContainer()->get(TraefikDirectory::class);
        self::assertInstanceOf(TraefikDirectory::class, $traefik);
        mkdir($traefik->dir(), 0o755, true);

        return $traefik;
    }

    private function live(): FakeLiveEntrypoints
    {
        $live = self::getContainer()->get(FakeLiveEntrypoints::class);
        self::assertInstanceOf(FakeLiveEntrypoints::class, $live);

        return $live;
    }

    public function testRefreshWritesTheMirrorFilesClearsTheMarkerAndReportsPendingOrLive(): void
    {
        $traefik = $this->traefik();
        file_put_contents($traefik->markerFile(), "9108\n");
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $this->cli('fleet:port:create', ['name' => 'mail', '--public' => '8025', '--router' => '18025']);

        $pending = $this->cli('fleet:port:refresh', ['--json' => true]);

        self::assertSame(0, $pending->getStatusCode(), $pending->getErrorOutput());
        $document = $this->json($pending);
        self::assertSame('pending', $document['mirror']['state'], 'the router is not up: never restarted from here');
        self::assertSame([9108], $document['mirror']['mirrored']);
        self::assertCount(1, $document['mirror']['skipped']);
        self::assertStringContainsString('port "mail" (public 8025) not mirrored', $document['mirror']['skipped'][0]);
        self::assertFileExists($traefik->staticFile());
        self::assertFileDoesNotExist($traefik->markerFile(), 'port:refresh is the explicit retry');

        $this->live()->script(['http-80', 'fleet-mirror-9108']);
        $human = $this->cli('fleet:port:refresh');
        self::assertSame(0, $human->getStatusCode());
        self::assertStringContainsString('router mirror: live', $human->getDisplay());
        self::assertStringContainsString('port "mail" (public 8025) not mirrored', $human->getDisplay());
        self::assertSame([], $this->recorded(), 'port:refresh never restarts the router');
    }

    /**
     * @return list<list<string>>
     */
    private function recorded(): array
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return array_map(static fn ($call): array => $call->argv, $runner->calls());
    }

    public function testMirrorShowsAndSwitchesTheSetting(): void
    {
        $traefik = $this->traefik();
        $this->cli('fleet:host:init', ['--domain' => 'fleet.example.com']);
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $this->cli('fleet:port:refresh');
        self::assertFileExists($traefik->staticFile());

        $shown = $this->cli('fleet:port:mirror', ['--json' => true]);
        self::assertSame(0, $shown->getStatusCode(), $shown->getErrorOutput());
        self::assertSame(['enabled' => true, 'mirrored' => [9108], 'skipped' => []], $this->json($shown));

        $off = $this->cli('fleet:port:mirror', ['state' => 'off', '--json' => true]);
        self::assertSame(0, $off->getStatusCode(), $off->getErrorOutput());
        self::assertSame(['enabled' => false, 'mirrored' => [], 'skipped' => []], $this->json($off));
        self::assertFileDoesNotExist($traefik->staticFile());
        self::assertStringContainsString('port mirror: off', $this->cli('fleet:port:mirror')->getDisplay());

        $on = $this->cli('fleet:port:mirror', ['state' => 'on', '--json' => true]);
        self::assertSame(['enabled' => true, 'mirrored' => [9108], 'skipped' => []], $this->json($on));
        self::assertFileExists($traefik->staticFile());

        $bad = $this->cli('fleet:port:mirror', ['state' => 'maybe']);
        self::assertSame(1, $bad->getStatusCode());
    }

    public function testMirrorSwitchOnAnUninitialisedHostIsRefused(): void
    {
        $refused = $this->cli('fleet:port:mirror', ['state' => 'off']);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('not initialised', $refused->getErrorOutput());
    }

    public function testRefreshOfAnUnknownProjectExits3(): void
    {
        $tester = $this->cli('fleet:port:refresh', ['--project' => 'nope']);

        self::assertSame(3, $tester->getStatusCode());
    }
}
