<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\PortMirror\RouterCopies;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\PortMirrorTestCase;
use Symfony\Component\Yaml\Yaml;

/** FLE-326: the router's own copies of the mirror files against the host files. */
final class RouterCopiesTest extends PortMirrorTestCase
{
    private const string STATIC_PATH = '/mnt/ddev-global-cache/traefik/.static_config.yaml';

    private RouterCopies $copies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolation(\App\Domain\Host\IsolationMode::Enforce);
        $this->catalogue(new NamedPort('typesense', 9108, 8108));
        $this->globalConfig("router_https_port: 8443\n");
        $this->mirror->sync();
        $this->copies = new RouterCopies($this->runner, $this->traefik, $this->live);
    }

    private function router(?string $dynamicCopy, ?string $staticCopy): void
    {
        $this->runner
            ->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"))
            ->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, '["traefik"] ["--configFile='.self::STATIC_PATH.'"] null'));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat', new RouterCopies($this->runner, $this->traefik, $this->live)->dynamicPath()], null === $dynamicCopy ? new CommandResult(1, '') : new CommandResult(0, $dynamicCopy));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat', self::STATIC_PATH], null === $staticCopy ? new CommandResult(1, '') : new CommandResult(0, $staticCopy));
    }

    private function hostDynamic(): string
    {
        return (string) file_get_contents($this->traefik->dynamicFile());
    }

    /** The router's merged static file as DDEV leaves it: the fragment's entry points without the PROXY trust. */
    private function oldStatic(): string
    {
        $fragment = Yaml::parse((string) file_get_contents($this->traefik->staticFile()));
        unset($fragment['entryPoints']['http-8443']);
        $fragment['entryPoints']['http-8443'] = ['address' => ':8443'];
        $fragment['log'] = ['level' => 'INFO'];

        return Yaml::dump($fragment, 10, 2);
    }

    private function currentStatic(): string
    {
        $fragment = Yaml::parse((string) file_get_contents($this->traefik->staticFile()));
        $fragment['entryPoints']['http-8443']['address'] = ':8443';
        $fragment['log'] = ['level' => 'INFO'];

        return Yaml::dump($fragment, 10, 2);
    }

    public function testAnUpgradedHostWithOldRouterCopiesPushesBothAndNeedsOneRestart(): void
    {
        $this->router('old dynamic', $this->oldStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertSame([], $repair['problems']);
        self::assertSame(['the port mirror dynamic file', 'the port mirror static configuration'], $repair['pushed']);
        self::assertTrue($repair['restartNeeded']);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'cp', $this->traefik->dynamicFile()]));
        // Both files are renamed into place (the dynamic one and the router's static file with the fragment merged in; its other keys stay).
        self::assertCount(2, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'mv', '-f']));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'mv', '-f', self::STATIC_PATH.'.fleet-tmp', self::STATIC_PATH]));
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']));
    }

    public function testNothingIsPushedOrRestartedWhenTheRouterAlreadyHoldsTheFilesAndTrustsTheProxy(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = true;

        $repair = $this->copies->repair();

        self::assertSame(['pushed' => [], 'restartNeeded' => false, 'problems' => [], 'staticDiffers' => false], $repair);
        self::assertSame([], $this->runner->callsMatching(['docker', 'cp']));
    }

    public function testACopyPushedEarlierWithoutARestartStillAsksForOne(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertSame([], $repair['pushed']);
        self::assertTrue($repair['restartNeeded'], 'the live entry point does not trust the PROXY header yet');
    }

    public function testAStoppedRouterIsLeftAlone(): void
    {
        $this->runner->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(1, ''));

        self::assertSame(['pushed' => [], 'restartNeeded' => false, 'problems' => [], 'staticDiffers' => false], $this->copies->repair());
        self::assertSame([], $this->copies->check());
    }

    public function testAnUnknownStaticFileIsReportedAndNoRestartIsAsked(): void
    {
        $this->runner->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"));
        $this->runner->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, '["traefik"] [] null'));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat'], new CommandResult(0, $this->hostDynamic()));
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertFalse($repair['restartNeeded']);
        self::assertNotSame([], $repair['problems']);
    }

    public function testAnUnreadableStaticFileIsAMismatchNeverUnknown(): void
    {
        $this->runner->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat'], new CommandResult(0, $this->hostDynamic()));

        $rows = $this->copies->check();

        $static = array_values(array_filter($rows, static fn (array $r): bool => str_contains($r['what'], 'static')));
        self::assertSame('unreachable', $static[0]['actual']);
        self::assertFalse($static[0]['ok']);
    }

    public function testCheckReportsEveryMismatch(): void
    {
        $this->router('old dynamic', $this->oldStatic());
        $this->live->proxyTrusted = false;

        $rows = $this->copies->check();

        self::assertCount(3, $rows);
        self::assertSame(['mismatch', 'mismatch', 'mismatch (restart pending)'], array_column($rows, 'actual'));
        self::assertSame([false, false, false], array_column($rows, 'ok'));
    }

    public function testCheckPassesWhenEverythingMatches(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = true;

        self::assertSame([true, true, true], array_column($this->copies->check(), 'ok'));
    }

    public function testAMirrorThatIsGoneRemovesTheRoutersDynamicCopy(): void
    {
        $this->switchMirror(false);
        $this->mirror->sync();
        self::assertFileDoesNotExist($this->traefik->dynamicFile());
        $this->router('left over', null);

        $repair = $this->copies->repair();

        self::assertSame(['removed the port mirror dynamic file'], $repair['pushed']);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'rm', '-f']));
    }

    public function testWithoutPushStaticADifferingStaticFileIsOnlyReported(): void
    {
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair(false);

        self::assertTrue($repair['staticDiffers']);
        self::assertFalse($repair['restartNeeded']);
        self::assertSame([], $repair['pushed']);
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']));
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'cp']));
        self::assertFileDoesNotExist($this->copies->backupFile());
    }

    public function testTheStaticFileIsReplacedByARenameInTheSameDirectoryNeverWrittenInPlace(): void
    {
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;

        $this->copies->repair();

        $cp = $this->runner->callsMatching(['docker', 'cp']);
        $tmpCopies = array_filter($cp, static fn ($c): bool => str_ends_with($c->argv[\count($c->argv) - 1], self::STATIC_PATH.'.fleet-tmp'));
        self::assertNotSame([], $tmpCopies, 'the new file is copied to a temp name next to the target');
        $mv = $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'mv', '-f', self::STATIC_PATH.'.fleet-tmp', self::STATIC_PATH]);
        self::assertCount(1, $mv, 'and renamed over it (atomic)');
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c', 'cat "$0" > "$1" && rm -f "$0"', self::STATIC_PATH.'.fleet-tmp']), 'no in-place write while the rename works');
    }

    public function testAnExistingBackupIsKeptWhileTheRouterIsNotVerifiedHealthy(): void
    {
        // A failed rollback left a bad merged file in the router; the next fragment change must not make it "the original".
        file_put_contents($this->copies->backupFile(), "ORIGINAL\n");
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;
        $this->live->script(null);

        $this->copies->repair();

        self::assertSame("ORIGINAL\n", (string) file_get_contents($this->copies->backupFile()));
    }

    public function testAnExistingBackupIsReplacedWhenTheRouterIsHealthy(): void
    {
        file_put_contents($this->copies->backupFile(), "STALE\n");
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;
        $this->live->script(['http-8443']);

        $this->copies->repair();

        self::assertSame($this->oldStatic(), (string) file_get_contents($this->copies->backupFile()));
    }

    public function testRestoreWritesTheSavedOriginalBackIntoTheRouter(): void
    {
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;
        $this->copies->repair();
        $before = \count($this->runner->calls());

        self::assertTrue($this->copies->restoreStatic());

        $after = \array_slice($this->runner->calls(), $before);
        $argvs = array_map(static fn ($c): string => implode(' ', $c->argv), $after);
        self::assertContains('docker cp '.$this->copies->backupFile().' ddev-router:'.self::STATIC_PATH.'.fleet-tmp', $argvs, 'the host backup is what goes back');
        self::assertContains('docker exec ddev-router mv -f '.self::STATIC_PATH.'.fleet-tmp '.self::STATIC_PATH, $argvs);
        self::assertSame($this->oldStatic(), (string) file_get_contents($this->copies->backupFile()));
    }

    public function testTheOriginalIsBackedUpBeforeTheStaticPush(): void
    {
        $this->router($this->hostDynamic(), $this->oldStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertSame(['the port mirror static configuration'], $repair['pushed']);
        self::assertSame($this->oldStatic(), (string) file_get_contents($this->copies->backupFile()));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'cp', '-f', self::STATIC_PATH, self::STATIC_PATH.'.fleet-backup']));
    }
}
