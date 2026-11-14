<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Port;

use App\Domain\Port\NamedPortRepository;
use App\Fleet\PortMirror\TraefikDirectory;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\FakeLiveEntrypoints;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['name' => 'typesense', 'public' => 9108, 'router' => 8108, 'access' => 'public', 'projects' => []], $this->json($created));

        $list = $this->cli('fleet:port:list');
        self::assertSame(0, $list->getStatusCode());
        self::assertMatchesRegularExpression('#\btypesense 9108 8108 public -#', $this->squash($list->getDisplay()));
        self::assertSame([['name' => 'typesense', 'public' => 9108, 'router' => 8108, 'access' => 'public', 'projects' => []]], $this->json($this->cli('fleet:port:list', ['--json' => true])));

        $deleted = $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode(), $deleted->getErrorOutput());
        self::assertSame(['deleted' => 'typesense'], $this->json($deleted));
        self::assertSame([], $this->json($this->cli('fleet:port:list', ['--json' => true])));
        self::assertSame(3, $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true])->getStatusCode());
    }

    public function testAccessDefaultsToGatedExceptForTypesenseAndCanBeChanged(): void
    {
        $dash = $this->cli('fleet:port:create', ['name' => 'ts-dashboard', '--public' => '9111', '--router' => '8110', '--json' => true]);
        self::assertSame('gated', $this->json($dash)['access']);

        $open = $this->cli('fleet:port:update', ['name' => 'ts-dashboard', '--access' => 'public', '--json' => true]);
        self::assertSame(0, $open->getStatusCode(), $open->getErrorOutput());
        self::assertSame('public', $this->json($open)['access']);

        $bad = $this->cli('fleet:port:update', ['name' => 'ts-dashboard', '--access' => 'open']);
        self::assertSame(1, $bad->getStatusCode());
        self::assertSame(3, $this->cli('fleet:port:update', ['name' => 'nope', '--access' => 'gated'])->getStatusCode());

        $explicit = $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108', '--access' => 'gated', '--json' => true]);
        self::assertSame('gated', $this->json($explicit)['access']);
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

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedOnTheCommandLine(): iterable
    {
        yield 'bad name' => [['name' => 'Bad', '--public' => '9001', '--router' => '8001'], '<name>: Use lowercase letters'];
        yield 'public out of range' => [['name' => 'a', '--public' => '65536', '--router' => '8001'], '--public: Enter a port number between 1 and 65535.'];
        yield 'router not a number' => [['name' => 'a', '--public' => '9001', '--router' => 'x1'], '--router: Enter a port number between 1 and 65535.'];
        yield 'reserved router' => [['name' => 'a', '--public' => '9001', '--router' => '8443'], '--router: This port is reserved for the host.'];
        yield 'router equals public' => [['name' => 'a', '--public' => '9001', '--router' => '9001'], '--router: The router port must differ from the public port.'];
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('refusedOnTheCommandLine')]
    public function testRefusalNamesTheArgumentOrOptionWithTheMessage(array $arguments, string $line): void
    {
        $refused = $this->cli('fleet:port:create', $arguments);

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString($line, $refused->getErrorOutput());
        self::assertSame([], self::getContainer()->get(NamedPortRepository::class)->all());
    }

    public function testCollisionsNameTheOptionWithTheMessage(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $taken = 'This port number is already used by another entry.';

        $public = $this->cli('fleet:port:create', ['name' => 'other', '--public' => '8108', '--router' => '8999']);
        $router = $this->cli('fleet:port:create', ['name' => 'other', '--public' => '9999', '--router' => '9108']);
        $name = $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9999', '--router' => '8999']);

        self::assertStringContainsString('--public: '.$taken, $public->getErrorOutput());
        self::assertStringContainsString('--router: '.$taken, $router->getErrorOutput());
        self::assertStringContainsString('<name>: A port with this name already exists.', $name->getErrorOutput());
        self::assertCount(1, self::getContainer()->get(NamedPortRepository::class)->all());
    }

    public function testDeletingAnUnknownPortSaysSo(): void
    {
        $absent = $this->cli('fleet:port:delete', ['name' => 'no-such-port', '--yes' => true]);

        self::assertSame(3, $absent->getStatusCode());
        self::assertStringContainsString('Named port "no-such-port" does not exist.', $absent->getErrorOutput());
    }

    public function testTheBoundaryPortsAreAccepted(): void
    {
        $low = $this->cli('fleet:port:create', ['name' => 'low', '--public' => '1', '--router' => '65535', '--json' => true]);
        $high = $this->cli('fleet:port:create', ['name' => 'high', '--public' => '65534', '--router' => '2', '--json' => true]);

        self::assertSame(0, $low->getStatusCode(), $low->getErrorOutput());
        self::assertSame(0, $high->getStatusCode(), $high->getErrorOutput());
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

    public function testDeletingAProjectReleasesItsPorts(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $this->cli('fleet:project:create', ['name' => 'blog', '--git' => 'https://git.example.com/acme/blog.git', '--port' => ['typesense']]);
        self::assertSame(2, $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true])->getStatusCode());

        self::assertSame(0, $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true])->getStatusCode());

        self::assertSame(0, $this->cli('fleet:port:delete', ['name' => 'typesense', '--yes' => true])->getStatusCode());
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
        self::assertMatchesRegularExpression('#\btypesense 9108 8108 public blog\b#', $this->squash($this->cli('fleet:port:list')->getDisplay()));
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
        // FLE-326: it reads the router (is it running, its copies); with the router not running it changes nothing and restarts nothing.
        self::assertSame([], array_values(array_filter($this->recorded(), static fn (array $argv): bool => 'inspect' !== ($argv[1] ?? ''))), 'port:refresh never restarts the router');
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
