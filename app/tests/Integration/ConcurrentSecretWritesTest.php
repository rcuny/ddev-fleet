<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Two processes save secrets at the same time (FLE-75): the second starts its transaction while the first still holds
 * the write lock, and must wait for it instead of failing with "database is locked".
 */
final class ConcurrentSecretWritesTest extends KernelTestCase
{
    private const ROUNDS = 15;

    private string $flags = '';

    protected function setUp(): void
    {
        $this->flags = sys_get_temp_dir().'/secret-flags-'.bin2hex(random_bytes(6));
        mkdir($this->flags, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->flags.'/*') ?: [] as $flag) {
            @unlink($flag);
        }
        @rmdir($this->flags);
        parent::tearDown();
    }

    public function testTwoProcessesSavingSecretsNeverHitADatabaseLock(): void
    {
        $scopeId = 'proj'.bin2hex(random_bytes(4));
        $script = \dirname(__DIR__).'/Support/bin/secret_writer.php';

        $writers = [];
        foreach (['a', 'b'] as $role) {
            $writers[$role] = new Process([\PHP_BINARY, $script, $role, $this->flags, $scopeId, (string) self::ROUNDS], timeout: 120);
            $writers[$role]->start();
        }
        foreach ($writers as $role => $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), \sprintf('Writer %s failed: %s', $role, $process->getErrorOutput()));
        }

        $secrets = self::getContainer()->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $secrets);
        self::assertCount(2 * self::ROUNDS, $secrets->listFor(Secret::SCOPE_PROJECT, $scopeId));
    }
}
