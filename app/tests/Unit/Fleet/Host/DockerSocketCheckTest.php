<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\Docker\DockerSocketCheck;
use PHPUnit\Framework\TestCase;

final class DockerSocketCheckTest extends TestCase
{
    public function testAMissingSocketIsNotOk(): void
    {
        $state = new DockerSocketCheck('/nonexistent/docker.sock')->check();

        self::assertFalse($state->ok);
        self::assertSame('The Docker socket does not exist.', $state->summary);
    }

    public function testAWorldWritableSocketIsReportedWithItsMode(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sock');
        chmod($file, 0o666);

        $state = new DockerSocketCheck($file)->check();
        unlink($file);

        self::assertFalse($state->ok);
        self::assertSame(0o666, $state->mode);
        self::assertStringContainsString('mode is 0666, expected 0660', $state->summary);
    }

    public function testRestoreSetsTheModeBackAndOnlyTheOwnerAndGroupStayWrong(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sock');
        chmod($file, 0o666);

        new DockerSocketCheck($file)->restore();
        $mode = fileperms($file) & 0o7777;
        unlink($file);

        self::assertSame(0o660, $mode);
    }
}
