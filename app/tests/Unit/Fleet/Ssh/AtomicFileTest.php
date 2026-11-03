<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\Ssh\AtomicFile;
use PHPUnit\Framework\TestCase;

final class AtomicFileTest extends TestCase
{
    private const string SECRET = 'PRIVATE-KEY-BODY-b3BlbnNzaC1rZXktdjEAAAAA';

    private string $dir = '';
    private string|false $previousIgnoreArgs = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-atomic-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        // Default PHP behaviour outside php.ini-production: exception traces carry call arguments. The suite config
        // may set 1, which would make a leak test vacuous.
        $this->previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        false !== $this->previousIgnoreArgs && ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        foreach ((array) glob($this->dir.'/{,.}*', \GLOB_BRACE) as $entry) {
            if (\is_string($entry) && is_dir($entry) && !\in_array(basename($entry), ['.', '..'], true)) {
                @rmdir($entry);
            } elseif (\is_string($entry) && is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testWriteReplacesAtomicallyWithTheRequestedMode(): void
    {
        AtomicFile::write($this->dir.'/k', 'one', 0o600);
        AtomicFile::write($this->dir.'/k', 'two', 0o600);

        self::assertSame('two', file_get_contents($this->dir.'/k'));
        self::assertSame(0o600, fileperms($this->dir.'/k') & 0o777);
        self::assertSame(['k'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function testACreateFailureDoesNotLeakTheContentsThroughTheTrace(): void
    {
        self::assertSame('0', \ini_get('zend.exception_ignore_args'));

        try {
            AtomicFile::write($this->dir.'/missing-dir/k', self::SECRET, 0o600);
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertTraceCarriesArgumentsButNotTheSecret($e);
        }
    }

    public function testARenameFailureDoesNotLeakTheContentsThroughTheTrace(): void
    {
        // Renaming a file over a directory fails after the temp file was written completely.
        mkdir($this->dir.'/target');

        try {
            AtomicFile::write($this->dir.'/target', self::SECRET, 0o600);
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('Cannot install "'.$this->dir.'/target".', $e->getMessage());
            $this->assertTraceCarriesArgumentsButNotTheSecret($e);
        }
        self::assertSame(['target'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])), 'the temp file is removed');
    }

    private function assertTraceCarriesArgumentsButNotTheSecret(\Throwable $e): void
    {
        $trace = print_r($e->getTrace(), true).$e->getTraceAsString().$e->getMessage();

        // Not vacuous: the (non-sensitive) path argument is recorded, so arguments are being captured.
        self::assertStringContainsString($this->dir, $trace);
        self::assertStringNotContainsString(self::SECRET, $trace);
        self::assertStringContainsString('SensitiveParameterValue', print_r($e->getTrace(), true));
    }
}
