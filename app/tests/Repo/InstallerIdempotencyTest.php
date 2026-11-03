<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Re-running is the upgrade path, so the plan is deterministic and every step is written to converge.
 */
final class InstallerIdempotencyTest extends TestCase
{
    use InstallerHarness;

    public function testTwoDryRunsPrintTheSamePlan(): void
    {
        $first = $this->dryRun();
        $second = $this->dryRun();

        self::assertSame(0, $first[0]);
        self::assertSame($first, $second);
    }

    public function testThePlanDoesNotDependOnWhatIsAlreadyInstalled(): void
    {
        // A host that already has docker, ddev, caddy and authelia prints the same plan (the real run skips instead).
        $bare = $this->dryRun();
        $stubs = $this->stubs([
            'docker' => 'exit 0', 'ddev' => 'exit 0', 'mkcert' => 'exit 0', 'dpkg' => 'exit 0',
            'caddy' => 'echo "v2.99.0 h1:x"',
        ]);
        $equipped = $this->dryRun(null, [], $stubs);

        self::assertSame($bare[1], $equipped[1]);
    }

    public function testPlanLinesAreStableAcrossDomains(): void
    {
        [, $a] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check']);
        [, $b] = $this->dryRun(['--domain=other.example.org', '--skip-dns-check']);

        self::assertSame(
            preg_replace('/fleet\.example\.com|other\.example\.org/', 'DOMAIN', $a),
            preg_replace('/fleet\.example\.com|other\.example\.org/', 'DOMAIN', $b),
        );
    }

    public function testVersionComparison(): void
    {
        foreach ([
            ['v2.11.3', 'v2.11.7', 0],
            ['2.11.7', 'v2.11.7', 1],
            ['v2.11.8', 'v2.11.7', 1],
            ['v2.9.0', 'v2.11.0', 0],
            ['v2.11.7', 'v2.11.10', 0],
            ['v3.0.0', 'v2.99.99', 1],
        ] as [$a, $b, $lt]) {
            [$status] = $this->withCommon('version_lt '.escapeshellarg($a).' '.escapeshellarg($b));
            self::assertSame(0 === $lt ? 0 : 1, $status, \sprintf('version_lt %s %s', $a, $b));
        }
    }

    public function testWriteFileIsAtomicAndReportsChanges(): void
    {
        $file = $this->scratch().'/etc/out.conf';
        mkdir(\dirname($file));
        $owner = posix_getuid().':'.posix_getgid();
        $code = \sprintf(
            'write_file %1$s 0640 %2$s <<<a; echo "first=$FILE_CHANGED"; write_file %1$s 0640 %2$s <<<a; echo "same=$FILE_CHANGED"; '.
            'write_file %1$s 0640 %2$s <<<b; echo "changed=$FILE_CHANGED"; write_file_once %1$s 0640 %2$s <<<c; echo "once=$FILE_CHANGED"',
            escapeshellarg($file),
            $owner,
        );

        [$status, $out] = $this->withCommon($code);

        self::assertSame(0, $status);
        self::assertSame("first=1\nsame=0\nchanged=1\nonce=0\n", $out);
        self::assertSame("b\n", file_get_contents($file));
        self::assertSame('640', substr(\sprintf('%o', fileperms($file)), -3));
        self::assertSame([$file], glob(\dirname($file).'/*'), 'no temp file is left behind');
    }

    public function testEnsureDirRepairsAMissingSetgidBit(): void
    {
        $dir = $this->scratch().'/snippets';
        mkdir($dir, 0o750);
        $owner = posix_getuid().':'.posix_getgid();

        [$status] = $this->withCommon('ensure_dir '.escapeshellarg($dir).' 2750 '.$owner);

        self::assertSame(0, $status);
        self::assertSame('2750', substr(\sprintf('%o', fileperms($dir)), -4));
    }
}
