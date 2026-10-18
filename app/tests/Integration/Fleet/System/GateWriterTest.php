<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\System;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateMode;
use App\Fleet\System\GateWriter;
use App\Tests\Support\SystemTemplates;
use PHPUnit\Framework\TestCase;

final class GateWriterTest extends TestCase
{
    private string $root;
    private RecordingCommandRunner $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/fleet-gate-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $this->runner = new RecordingCommandRunner();
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    private function writer(?string $caddyDir = null): GateWriter
    {
        $paths = new FleetPaths($this->root.'/srv', $caddyDir ?? $this->root.'/srv/caddy');

        return new GateWriter(SystemTemplates::twig(), $paths, new CaddyReloader($this->runner, $paths));
    }

    private function gate(string $caddyDir): string
    {
        return $caddyDir.'/gate.conf';
    }

    public function testSetupGateAllowsOnlyWizardAssetsAndHealth(): void
    {
        $this->writer()->write(GateMode::Setup);

        $gate = (string) file_get_contents($this->gate($this->root.'/srv/caddy'));
        self::assertStringContainsString("@fleet_closed not path /setup /setup/* /assets/* /health\n", $gate);
        self::assertStringContainsString("redir @fleet_closed /setup 302\n", $gate);
        self::assertStringNotContainsString('forward_auth', $gate);
        $verbs = array_map(static fn ($call): string => $call->argv[1], $this->runner->calls());
        self::assertSame(['validate', 'reload'], $verbs);
    }

    public function testAutheliaGateExemptsHealthAndHooks(): void
    {
        $this->writer()->write(GateMode::Authelia);

        $gate = (string) file_get_contents($this->gate($this->root.'/srv/caddy'));
        self::assertStringContainsString("@fleet_gated not path /health /hooks/*\n", $gate);
        self::assertStringContainsString("forward_auth @fleet_gated 127.0.0.1:9091 {\n    uri /api/authz/forward-auth\n    copy_headers Remote-User Remote-Groups Remote-Name Remote-Email\n}\n", $gate);
        self::assertStringNotContainsString('redir', $gate);
    }

    public function testEveryCopiedHeaderIsOneTheCaddyfileStripsFirst(): void
    {
        $gate = $this->writer()->render(GateMode::Authelia);

        preg_match('/copy_headers (.+)/', $gate, $m);
        $copied = explode(' ', trim($m[1] ?? ''));
        self::assertNotSame([''], $copied);
        self::assertSame([], array_diff($copied, CaddyfileRenderer::strippedHeaders()));
    }

    public function testWritesMode0640(): void
    {
        $previous = umask(0o000);
        try {
            $this->writer()->write(GateMode::Setup);
        } finally {
            umask($previous);
        }

        clearstatcache();
        self::assertSame(0o640, fileperms($this->gate($this->root.'/srv/caddy')) & 0o777);
    }

    public function testReloadFailureRestoresPreviousGate(): void
    {
        $writer = $this->writer();
        $writer->write(GateMode::Setup);
        $before = (string) file_get_contents($this->gate($this->root.'/srv/caddy'));

        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', "Error: Caddyfile:3: nope\n"));
        try {
            $writer->write(GateMode::Authelia);
            self::fail('Expected the reload failure to surface.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
        }

        self::assertSame($before, file_get_contents($this->gate($this->root.'/srv/caddy')));
        self::assertSame(['gate.conf'], array_values(array_diff((array) scandir($this->root.'/srv/caddy'), ['.', '..'])), 'no temp file is left behind');
    }

    public function testFailureOfAFirstWriteRemovesTheFile(): void
    {
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', "bad\n"));

        try {
            $this->writer()->write(GateMode::Setup);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->gate($this->root.'/srv/caddy'));
        }
    }

    public function testAReloadErrorAlsoRestores(): void
    {
        $writer = $this->writer();
        $writer->write(GateMode::Setup);
        $before = (string) file_get_contents($this->gate($this->root.'/srv/caddy'));

        $this->runner->expect(['caddy', 'reload'], new CommandResult(1, '', "admin API unreachable\n"));
        try {
            $writer->write(GateMode::Authelia);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            self::assertSame($before, file_get_contents($this->gate($this->root.'/srv/caddy')));
        }
    }

    public function testMissingSetgidOutsideHomeIsRefused(): void
    {
        $dir = $this->root.'/etc/caddy/fleet';
        mkdir($dir, 0o750, true);
        chmod($dir, 0o750);

        try {
            $this->writer($dir)->write(GateMode::Setup);
            self::fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('setgid', $e->getMessage());
            self::assertStringContainsString('chgrp -R caddy '.escapeshellarg($dir), $e->getMessage());
            self::assertStringContainsString('chmod 2750', $e->getMessage());
            self::assertStringContainsString('systemctl restart caddy', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->gate($dir));
        self::assertSame([], $this->runner->calls(), 'nothing is validated or reloaded');
    }

    public function testAMissingDirectoryOutsideHomeIsRefusedNotCreated(): void
    {
        $dir = $this->root.'/etc/caddy/fleet';

        try {
            $this->writer($dir)->write(GateMode::Setup);
            self::fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('chgrp -R caddy', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testADirectoryOutsideHomeWithSetgidIsUsed(): void
    {
        $dir = $this->root.'/etc/caddy/fleet';
        mkdir($dir, 0o750, true);
        chmod($dir, 0o2750);
        clearstatcache();
        if (0 === (fileperms($dir) & 0o2000)) {
            self::markTestSkipped('This filesystem does not keep the setgid bit.');
        }

        $this->writer($dir)->write(GateMode::Setup);

        self::assertFileExists($this->gate($dir));
    }

    public function testADirectoryInsideHomeIsCreated(): void
    {
        self::assertDirectoryDoesNotExist($this->root.'/srv/caddy');

        $this->writer()->write(GateMode::Setup);

        self::assertDirectoryExists($this->root.'/srv/caddy');
    }

    public function testASiblingOfHomeIsNotInsideHome(): void
    {
        // "/srv-evil" shares a string prefix with "/srv" but is outside it.
        $dir = $this->root.'/srv-evil/caddy';
        mkdir($dir, 0o750, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('setgid');

        $this->writer($dir)->write(GateMode::Setup);
    }
}
