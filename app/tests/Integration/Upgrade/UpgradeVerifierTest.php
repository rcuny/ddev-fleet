<?php

declare(strict_types=1);

namespace App\Tests\Integration\Upgrade;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\InstalledVersion;
use App\Fleet\Upgrade\PendingMigrations;
use App\Fleet\Upgrade\UpgradeVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UpgradeVerifier with a scripted command runner (curl, git, systemctl) and the real audit log of the test database.
 */
final class UpgradeVerifierTest extends KernelTestCase
{
    private const string SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $versionFile;

    protected function setUp(): void
    {
        $this->versionFile = sys_get_temp_dir().'/verifier-version-'.bin2hex(random_bytes(4));
        file_put_contents($this->versionFile, "v1.2.1\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->versionFile);
        parent::tearDown();
    }

    /**
     * @param list<string> $failing argv prefixes (joined by space) that fail
     */
    private function verifier(array $failing = [], int $pending = 0, string $head = self::SHA, bool $throwOnMigrations = false): UpgradeVerifier
    {
        self::bootKernel();
        $runner = new class($failing, $head) implements CommandRunner {
            /** @var list<string> */
            public array $calls = [];

            /** @param list<string> $failing */
            public function __construct(private array $failing, private string $head)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $this->calls[] = implode(' ', $argv);
                foreach ($this->failing as $needle) {
                    if (str_contains(implode(' ', $argv), $needle)) {
                        return new CommandResult(1);
                    }
                }

                return new CommandResult(0, 'git' === $argv[0] ? $this->head."\n" : '');
            }
        };
        $migrations = new class($pending, $throwOnMigrations) implements PendingMigrations {
            public function __construct(private int $pending, private bool $throw)
            {
            }

            public function count(): int
            {
                return $this->throw ? throw new \RuntimeException('no database') : $this->pending;
            }
        };
        $audit = self::getContainer()->get(AuditLog::class);

        return new UpgradeVerifier($runner, new InstalledVersion($runner, $this->versionFile, '/nonexistent'), $migrations, $audit, 'https://fleet.example.com/', '/opt/ddev-fleet');
    }

    /** @return array<string, string> name => ok|FAIL|warn */
    private function outcome(UpgradeVerifier $verifier, string $tag = 'v1.2.1', ?string $sha = self::SHA): array
    {
        $out = [];
        foreach ($verifier->verify('v1.1.0', $tag, $sha, 'alice')->results as $r) {
            $out[$r->name] = $r->ok ? 'ok' : ($r->critical ? 'FAIL' : 'warn');
        }

        return $out;
    }

    public function testEverythingHealthyPassesAndWritesTheAuditRow(): void
    {
        $verifier = $this->verifier();
        $report = $verifier->verify('v1.1.0', 'v1.2.1', self::SHA, 'alice');

        self::assertTrue($report->passed());
        self::assertSame(['health', 'authelia', 'version', 'checkout', 'migrations', 'workers', 'audit'], array_map(static fn ($r): string => $r->name, $report->results));
        $rows = self::getContainer()->get(AuditLog::class)->latest(1, AuditLog::HOST_UPGRADE);
        self::assertCount(1, $rows);
        self::assertSame('alice', $rows[0]->actor());
        self::assertSame('v1.1.0 -> v1.2.1', $rows[0]->subject());
    }

    public function testEachCriticalStepFailsTheVerification(): void
    {
        self::assertSame('FAIL', $this->outcome($this->verifier(['fleet.example.com/health']))['health']);
        self::assertSame('FAIL', $this->outcome($this->verifier(['9091/api/health']))['authelia']);
        self::assertSame('FAIL', $this->outcome($this->verifier(['is-active --quiet fleet-worker@1']))['workers']);
        self::assertSame('FAIL', $this->outcome($this->verifier(pending: 2))['migrations']);
        self::assertSame('FAIL', $this->outcome($this->verifier(throwOnMigrations: true))['migrations']);
        self::assertSame('FAIL', $this->outcome($this->verifier(), 'v1.3.0')['version'], 'installed-version differs from the target');
        self::assertSame('FAIL', $this->outcome($this->verifier(head: str_repeat('c', 40)))['checkout'], 'a moved tag');
        self::assertSame('ok', $this->outcome($this->verifier(), sha: null)['checkout'], 'no sha, nothing to compare');
    }
}
