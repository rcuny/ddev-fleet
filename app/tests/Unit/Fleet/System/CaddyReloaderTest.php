<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyReloader;
use PHPUnit\Framework\TestCase;

final class CaddyReloaderTest extends TestCase
{
    private RecordingCommandRunner $runner;
    private CaddyReloader $reloader;

    protected function setUp(): void
    {
        $this->runner = new RecordingCommandRunner();
        $this->reloader = new CaddyReloader($this->runner, new FleetPaths('/srv/fleet', '/etc/caddy/fleet'));
    }

    public function testValidatesThenReloadsTheMainCaddyfileWithoutSudo(): void
    {
        $this->reloader->validateAndReload();

        $calls = $this->runner->calls();
        self::assertCount(2, $calls);
        self::assertSame(['caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], $calls[0]->argv);
        self::assertSame(['caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], $calls[1]->argv);
    }

    public function testAFailedValidationNeverReloads(): void
    {
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', "Error: adapting config: Caddyfile:9: bad\n"));

        try {
            $this->reloader->validateAndReload();
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('validate failed (exit 1)', $e->getMessage());
            self::assertStringContainsString('Caddyfile:9: bad', $e->getMessage());
        }
        self::assertCount(1, $this->runner->calls());
    }

    public function testAFailedReloadIsReported(): void
    {
        $this->runner->expect(['caddy', 'reload'], new CommandResult(1, '', "connection refused\n"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reload failed (exit 1): connection refused');

        $this->reloader->validateAndReload();
    }
}
