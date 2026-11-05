<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `apply slices` of install/libexec/apply, run unprivileged against a scratch tree (FLEET_APPLY_TEST_ROOT) with stub
 * systemctl and nproc on PATH. The stubs log every call, so the tests assert what ran and in which order. Nothing
 * here touches the real /etc, /srv or systemd.
 */
final class ApplySlicesHelperTest extends TestCase
{
    use InstallerHarness;

    private const string MARK = '# Managed by ddev-fleet (apply slices). Hand edits are overwritten.';

    private string $root = '';
    private string $log = '';
    private string $failing = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The helper ignores FLEET_APPLY_TEST_ROOT as root, so these unprivileged scratch-tree tests cannot run as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        $this->failing = $this->scratch().'/failing';
        foreach (['srv/fleet/staging/slices', 'etc/systemd/system'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o700, true);
        }
        touch($this->log);
        touch($this->failing);
    }

    public function testInstallsTheTreeStartsParentsFirstAndAppliesEveryQuotaLive(): void
    {
        $this->stage([
            ['unit' => 'fleet-blog_site-gates.slice', 'quota_percent' => 400],
            ['unit' => 'fleet.slice', 'quota_percent' => 1400],
            ['unit' => 'fleet-blog_site.slice', 'quota_percent' => 1200],
            ['unit' => 'fleet-blog.slice', 'quota_percent' => null],
        ]);

        [$status, $out, $err] = $this->apply();

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('slices installed (4, 0 removed)', $out);
        $unit = $this->read('etc/systemd/system/fleet-blog_site.slice');
        self::assertStringStartsWith(self::MARK."\n[Unit]\n", $unit);
        self::assertStringContainsString("[Slice]\nCPUAccounting=yes\n", $unit);
        self::assertSame(self::MARK."\n[Slice]\nCPUQuota=1200%\n", $this->read('etc/systemd/system/fleet-blog_site.slice.d/10-fleet-quota.conf'));
        self::assertSame(self::MARK."\n[Slice]\nCPUQuota=1400%\n", $this->read('etc/systemd/system/fleet.slice.d/10-fleet-quota.conf'));
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet-blog.slice.d', 'an unlimited slice has no drop-in');
        self::assertFileExists($this->root.'/etc/systemd/system/fleet-blog.slice');

        $calls = $this->calls();
        self::assertSame(['systemctl daemon-reload'], \array_slice($calls, 0, 1));
        self::assertSame(
            [
                'systemctl start fleet.slice',
                'systemctl start fleet-blog.slice',
                'systemctl start fleet-blog_site.slice',
                'systemctl start fleet-blog_site-gates.slice',
                'systemctl set-property --runtime fleet.slice CPUQuota=1400%',
                'systemctl set-property --runtime fleet-blog.slice CPUQuota=',
                'systemctl set-property --runtime fleet-blog_site.slice CPUQuota=1200%',
                'systemctl set-property --runtime fleet-blog_site-gates.slice CPUQuota=400%',
            ],
            \array_slice($calls, 1),
            'reload, then every slice started parents first, then the quotas on the running slices (never --persistent)',
        );
        self::assertSame([], $this->staged(), 'the staged file is consumed');
    }

    public function testAQuotaChangeRewritesTheDropInAndAppliesItLive(): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => 'fleet-p.slice', 'quota_percent' => 400]]);
        self::assertSame(0, $this->apply()[0]);
        file_put_contents($this->log, '');

        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => 'fleet-p.slice', 'quota_percent' => 800]]);
        [$status, , $err] = $this->apply();

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('CPUQuota=800%', $this->read('etc/systemd/system/fleet-p.slice.d/10-fleet-quota.conf'));
        self::assertContains('systemctl set-property --runtime fleet-p.slice CPUQuota=800%', $this->calls());
    }

    public function testRemovingALimitDeletesTheDropInAndLiftsTheLiveQuota(): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => 'fleet-p.slice', 'quota_percent' => 400]]);
        self::assertSame(0, $this->apply()[0]);
        file_put_contents($this->log, '');

        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => 'fleet-p.slice', 'quota_percent' => null]]);
        [$status, , $err] = $this->apply();

        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet-p.slice.d/10-fleet-quota.conf');
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet-p.slice.d');
        self::assertContains('systemctl set-property --runtime fleet-p.slice CPUQuota=', $this->calls());
    }

    public function testStaleSlicesItOwnsAreRemovedAndForeignOnesAreLeftAlone(): void
    {
        $dir = $this->root.'/etc/systemd/system';
        file_put_contents($dir.'/fleet-old.slice', self::MARK."\n[Slice]\n");
        mkdir($dir.'/fleet-old.slice.d');
        file_put_contents($dir.'/fleet-old.slice.d/10-fleet-quota.conf', self::MARK."\n[Slice]\nCPUQuota=200%\n");
        file_put_contents($dir.'/fleet-mine.slice', "[Slice]\nCPUQuota=50%\n");
        file_put_contents($dir.'/fleet-worker@.service', "[Service]\n");
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400]]);

        [$status, $out, $err] = $this->apply();

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('1 removed', $out);
        self::assertFileDoesNotExist($dir.'/fleet-old.slice');
        self::assertFileDoesNotExist($dir.'/fleet-old.slice.d');
        self::assertFileExists($dir.'/fleet-mine.slice', 'a slice without the marker is not ours');
        self::assertFileExists($dir.'/fleet-worker@.service');
        self::assertContains('systemctl set-property --runtime fleet-old.slice CPUQuota=', $this->calls(), 'the removed slice stops limiting, its containers are not killed');
        self::assertNotContains('systemctl stop fleet-old.slice', $this->calls());
    }

    public function testItRefusesToReplaceAUnitItDoesNotOwn(): void
    {
        file_put_contents($this->root.'/etc/systemd/system/fleet-p.slice', "[Slice]\nCPUQuota=50%\n");
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => 'fleet-p.slice', 'quota_percent' => 400]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertStringContainsString('not managed by ddev-fleet', $err);
        self::assertSame("[Slice]\nCPUQuota=50%\n", $this->read('etc/systemd/system/fleet-p.slice'));
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet.slice', 'nothing is installed when one entry is refused');
        self::assertSame([], $this->calls());
    }

    public function testItRefusesToReplaceOrDeleteADropInItDoesNotOwn(): void
    {
        mkdir($this->root.'/etc/systemd/system/fleet.slice.d');
        file_put_contents($this->root.'/etc/systemd/system/fleet.slice.d/10-fleet-quota.conf', "[Slice]\nCPUQuota=50%\n");
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertStringContainsString('drop-in of fleet.slice is not managed by ddev-fleet', $err);
        self::assertSame("[Slice]\nCPUQuota=50%\n", $this->read('etc/systemd/system/fleet.slice.d/10-fleet-quota.conf'));
        self::assertSame([], $this->calls());
    }

    public function testAStaleSliceWithAForeignDropInIsRefusedNotStripped(): void
    {
        file_put_contents($this->root.'/etc/systemd/system/fleet-old.slice', self::MARK."\n[Unit]\nDescription=x\n");
        mkdir($this->root.'/etc/systemd/system/fleet-old.slice.d');
        file_put_contents($this->root.'/etc/systemd/system/fleet-old.slice.d/10-fleet-quota.conf', "[Slice]\nCPUQuota=50%\n");
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertFileExists($this->root.'/etc/systemd/system/fleet-old.slice.d/10-fleet-quota.conf');
        self::assertSame([], $this->calls());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badUnitNames(): iterable
    {
        foreach ([
            'path traversal' => '../fleet-x.slice',
            'absolute path' => '/etc/passwd',
            'other suffix' => 'fleet-x.service',
            'no fleet prefix' => 'docker.slice',
            'system slice' => 'system.slice',
            'uppercase' => 'fleet-X.slice',
            'dash in a component beyond two levels' => 'fleet-a-b-c.slice',
            'empty component' => 'fleet--a.slice',
            'trailing dash' => 'fleet-a-.slice',
            'space' => 'fleet-a b.slice',
            'newline' => "fleet-a.slice\nfleet-b.slice",
            'tab' => "fleet-a\t.slice",
            'template instance' => 'fleet-a@x.slice',
            'dots' => 'fleet-a.b.slice',
            'empty' => '',
            'too long' => 'fleet-'.str_repeat('a', 220).'.slice',
        ] as $label => $name) {
            yield $label => [$name];
        }
    }

    #[DataProvider('badUnitNames')]
    public function testRefusesABadUnitName(string $name): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1400], ['unit' => $name, 'quota_percent' => 100]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls(), 'no systemctl call for a refused list');
        self::assertSame([], $this->unitFiles(), 'nothing is written');
        self::assertSame([], $this->staged(), 'the refused file is removed too');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badQuotas(): iterable
    {
        yield 'above threads x 100 (16 threads)' => [1700];
        yield 'far above' => [100000];
        yield 'below one thread' => [99];
        yield 'zero' => [0];
        yield 'negative' => [-200];
        yield 'fraction' => [250.5];
        yield 'string' => ['400'];
        yield 'string with unit' => ['400%'];
        yield 'bool' => [true];
        yield 'array' => [[400]];
        yield 'exponent that is huge' => [1.0e30];
    }

    #[DataProvider('badQuotas')]
    public function testRefusesABadQuota(mixed $quota): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 800], ['unit' => 'fleet-p.slice', 'quota_percent' => $quota]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls());
        self::assertSame([], $this->unitFiles());
    }

    public function testTheLargestAllowedQuotaIsThreadsTimesOneHundredInclusive(): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1600]]);
        self::assertSame(0, $this->apply()[0]);

        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 1601]]);
        [$status, , $err] = $this->apply();
        self::assertSame(65, $status, $err);
        self::assertStringContainsString('16 threads', $err);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'not json' => ['fleet.slice'];
        yield 'empty list' => ['[]'];
        yield 'object instead of list' => ['{"unit":"fleet.slice","quota_percent":100}'];
        yield 'list of strings' => ['["fleet.slice"]'];
        yield 'missing quota key' => ['[{"unit":"fleet.slice"}]'];
        yield 'missing unit key' => ['[{"quota_percent":100}]'];
        yield 'extra key' => ['[{"unit":"fleet.slice","quota_percent":100,"exec":"x"}]'];
        yield 'unit not a string' => ['[{"unit":1,"quota_percent":100}]'];
        yield 'duplicate unit' => ['[{"unit":"fleet.slice","quota_percent":100},{"unit":"fleet.slice","quota_percent":100}]'];
        yield 'no root slice' => ['[{"unit":"fleet-p.slice","quota_percent":100}]'];
        yield 'a project slice without the root' => ['[{"unit":"fleet-p-t.slice","quota_percent":100}]'];
        yield 'a template without its project' => ['[{"unit":"fleet.slice","quota_percent":100},{"unit":"fleet-p-t.slice","quota_percent":100}]'];
    }

    #[DataProvider('malformedDocuments')]
    public function testRefusesAMalformedDocument(string $json): void
    {
        file_put_contents($this->root.'/srv/fleet/staging/slices/slices.json', $json);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls());
        self::assertSame([], $this->unitFiles());
        self::assertSame([], $this->staged());
    }

    public function testMoreThanTheMaximumNumberOfSlicesIsRefused(): void
    {
        $list = [['unit' => 'fleet.slice', 'quota_percent' => 800]];
        for ($i = 0; $i < 500; ++$i) {
            $list[] = ['unit' => 'fleet-p'.$i.'.slice', 'quota_percent' => null];
        }
        $this->stage($list);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls());
    }

    public function testNothingStagedIsExitSixtySix(): void
    {
        [$status, , $err] = $this->apply();

        self::assertSame(66, $status, $err);
        self::assertSame([], $this->calls());
    }

    public function testSymlinkedStagingDirectoryIsRefused(): void
    {
        $elsewhere = $this->scratch().'/elsewhere';
        mkdir($elsewhere);
        file_put_contents($elsewhere.'/slices.json', json_encode([['unit' => 'fleet.slice', 'quota_percent' => 100]]));
        rmdir($this->root.'/srv/fleet/staging/slices');
        symlink($elsewhere, $this->root.'/srv/fleet/staging/slices');

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls());
        self::assertSame([], $this->unitFiles());
        self::assertFileExists($elsewhere.'/slices.json', 'the helper does not delete through a symlinked staging directory');
    }

    public function testSymlinkedStagedFileIsRefused(): void
    {
        $outside = $this->scratch().'/outside.json';
        file_put_contents($outside, json_encode([['unit' => 'fleet.slice', 'quota_percent' => 100]]));
        symlink($outside, $this->root.'/srv/fleet/staging/slices/slices.json');

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertStringContainsString('symlink', $err);
        self::assertSame([], $this->calls());
        self::assertFileExists($outside);
    }

    public function testASymlinkedDropInDirectoryIsRefused(): void
    {
        $elsewhere = $this->scratch().'/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->root.'/etc/systemd/system/fleet.slice.d');
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 800]]);

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], scandir($elsewhere) ? array_values(array_diff(scandir($elsewhere), ['.', '..'])) : ['?'], 'nothing is written through the link');
    }

    public function testOversizedFileIsRefused(): void
    {
        file_put_contents($this->root.'/srv/fleet/staging/slices/slices.json', str_repeat(' ', 262145));

        [$status, , $err] = $this->apply();

        self::assertSame(65, $status, $err);
        self::assertSame([], $this->calls());
    }

    #[DataProvider('failingCalls')]
    public function testAFailureRestoresThePreviousUnitsAndQuotas(string $failingCall, string $expectedMessage): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 800], ['unit' => 'fleet-p.slice', 'quota_percent' => 400]]);
        self::assertSame(0, $this->apply()[0]);
        $previousUnit = $this->read('etc/systemd/system/fleet-p.slice');
        $previousDropIn = $this->read('etc/systemd/system/fleet-p.slice.d/10-fleet-quota.conf');
        file_put_contents($this->log, '');
        file_put_contents($this->failing, $failingCall."\n");

        $this->stage([
            ['unit' => 'fleet.slice', 'quota_percent' => 800],
            ['unit' => 'fleet-p.slice', 'quota_percent' => 600],
            ['unit' => 'fleet-new.slice', 'quota_percent' => 200],
        ]);
        [$status, , $err] = $this->apply();

        self::assertSame(1, $status, $err);
        self::assertStringContainsString($expectedMessage, $err);
        self::assertSame($previousUnit, $this->read('etc/systemd/system/fleet-p.slice'));
        self::assertSame($previousDropIn, $this->read('etc/systemd/system/fleet-p.slice.d/10-fleet-quota.conf'), 'the old drop-in is back');
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet-new.slice', 'a unit this run created is removed again');
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet-new.slice.d');
        $calls = $this->calls();
        self::assertSame(2, \count(array_filter($calls, static fn (string $c): bool => 'systemctl daemon-reload' === $c)), 'reloaded again after the rollback');
        self::assertContains('systemctl set-property --runtime fleet-p.slice CPUQuota=400%', $calls, 'the previous live quota is re-applied');
        self::assertSame([], $this->staged());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingCalls(): iterable
    {
        yield 'a slice does not start' => ['start fleet-new.slice', 'could not start fleet-new.slice'];
        yield 'a quota cannot be applied' => ['set-property --runtime fleet-new.slice CPUQuota=200%', 'could not set the quota of fleet-new.slice'];
    }

    public function testAFailedReloadRestoresThePreviousUnits(): void
    {
        $this->stage([['unit' => 'fleet.slice', 'quota_percent' => 800]]);
        file_put_contents($this->failing, "daemon-reload\n");
        // Only the first reload may fail, the rollback's must work: the stub fails a call while the counter file allows it.
        file_put_contents($this->scratch().'/fail-once', "daemon-reload\n");

        [$status, , $err] = $this->apply(failOnce: 'daemon-reload');

        self::assertSame(1, $status, $err);
        self::assertStringContainsString('did not reload', $err);
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet.slice');
        self::assertFileDoesNotExist($this->root.'/etc/systemd/system/fleet.slice.d');
    }

    public function testTheOtherTargetsAndUnknownOnesStayRefused(): void
    {
        foreach ([['slice'], ['slices', 'extra'], ['SLICES'], ['slices '], ['../slices']] as $args) {
            [$status, , $err] = $this->apply($args);
            self::assertSame(64, $status, json_encode($args).': '.$err);
        }
        self::assertSame([], $this->calls());
    }

    public function testTheHelperGeneratesTheFilesAndNeverCopiesStagedText(): void
    {
        $code = implode("\n", self::codeLines(self::installDir().'/libexec/apply'));
        $slices = $this->between($code, 'apply_slices() {', "\npin_staging || true");

        self::assertStringNotContainsString('PRIV/slices.json" "', $slices, 'the staged JSON is parsed, never installed');
        self::assertStringContainsString('put_file "$PRIV/new.unit"', $slices);
        self::assertStringNotContainsString('--persistent', $code);
        self::assertStringNotContainsString('systemctl_slice stop', $slices, 'a removed slice is never stopped (its containers would die)');
        self::assertStringNotContainsString('eval ', $slices);
    }

    /**
     * @param list<mixed> $list
     */
    private function stage(array $list): void
    {
        file_put_contents($this->root.'/srv/fleet/staging/slices/slices.json', json_encode($list, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function apply(array $args = ['slices'], ?string $failOnce = null): array
    {
        $stubs = $this->stubs([
            'nproc' => 'echo 16',
            'systemctl' => <<<SH
                echo "systemctl \$*" >> {$this->log}
                if grep -qxF -- "\$*" {$this->failing} 2>/dev/null; then
                  case "\$*" in "{$failOnce}")
                    if [ -f {$this->scratch()}/failed-once ]; then exit 0; fi
                    touch {$this->scratch()}/failed-once ;;
                  esac
                  exit 1
                fi
                exit 0
                SH,
        ]);
        $process = new Process(['bash', self::installDir().'/libexec/apply', ...$args], null, ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')], null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log)), static fn (string $l): bool => '' !== $l));
    }

    /**
     * @return list<string>
     */
    private function staged(): array
    {
        $dir = $this->root.'/srv/fleet/staging/slices';

        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    /**
     * @return list<string>
     */
    private function unitFiles(): array
    {
        return array_values(array_diff(scandir($this->root.'/etc/systemd/system') ?: [], ['.', '..']));
    }

    private function read(string $relative): string
    {
        $text = file_get_contents($this->root.'/'.$relative);
        self::assertNotFalse($text, $relative);

        return $text;
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($text, $to, $start);
        self::assertNotFalse($end, $to);

        return substr($text, $start, $end - $start);
    }
}
