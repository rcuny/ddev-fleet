<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Domain\Host\IsolationMode;
use App\Fleet\Isolation\IsolationInput;
use App\Fleet\Isolation\IsolationRuleset;
use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-299: `apply isolation`, run unprivileged against a scratch tree with an nft stub: it validates the staged ruleset,
 * checks and loads it, keeps a copy for the boot unit (and drops it for the disable form), reports the live table when nothing is staged.
 */
final class ApplyIsolationHelperTest extends TestCase
{
    use InstallerHarness;

    private string $root = '';
    private string $log = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The helper ignores FLEET_APPLY_TEST_ROOT as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/nft.log';
        foreach (['srv/fleet/staging/isolation', 'etc/ddev-fleet'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o700, true);
        }
        touch($this->log);
    }

    /**
     * @return array{int, string, string}
     */
    private function apply(?string $staged, string $nft = 'exit 0'): array
    {
        if (null !== $staged) {
            file_put_contents($this->root.'/srv/fleet/staging/isolation/fleet_isolation.nft', $staged);
        }
        $stubs = $this->stubs(['nft' => "echo \"nft \$*\" >> {$this->log}\n{$nft}"]);
        $process = new Process(['bash', self::installDir().'/libexec/apply', 'isolation'], null, ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')], null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    private function ruleset(): string
    {
        return IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', ['172.18.0.2'], [9108], [], [], [['172.18.0.5', '172.18.0.6', 80]], [['172.18.0.5', 22]]));
    }

    public function testItChecksThenLoadsTheRulesetAndKeepsACopyForTheBootUnit(): void
    {
        [$status, $out, $err] = $this->apply($this->ruleset());

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('isolation ruleset installed', $out);
        $calls = array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
        self::assertCount(2, $calls);
        self::assertMatchesRegularExpression('~^nft -c -f \S+/fleet_isolation\.nft$~', $calls[0]);
        self::assertMatchesRegularExpression('~^nft -f \S+/fleet_isolation\.nft$~', $calls[1]);
        self::assertSame($this->ruleset(), file_get_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft'));
        self::assertFileDoesNotExist($this->root.'/srv/fleet/staging/isolation/fleet_isolation.nft', 'the staged file is consumed');
    }

    public function testTheDisableFormRemovesTheCopyAndDeletesTheTable(): void
    {
        file_put_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft', 'old');

        [$status, $out] = $this->apply(IsolationRuleset::disabled());

        self::assertSame(0, $status);
        self::assertStringContainsString('isolation table removed', $out);
        self::assertFileDoesNotExist($this->root.'/etc/ddev-fleet/fleet_isolation.nft');
    }

    public function testNothingStagedPrintsTheLiveTableAndChangesNothing(): void
    {
        [$status, $out] = $this->apply(null, 'echo \'{"nftables":[{"table":{"name":"fleet_isolation"}}]}\'');

        self::assertSame(0, $status);
        self::assertStringContainsString('"fleet_isolation"', $out);
        self::assertStringContainsString('nft -j list table inet fleet_isolation', (string) file_get_contents($this->log));
        self::assertStringNotContainsString('nft -f', (string) file_get_contents($this->log));
    }

    public function testAMissingTablePrintsAnEmptyDocument(): void
    {
        [$status, $out] = $this->apply(null, 'exit 1');

        self::assertSame(0, $status);
        self::assertSame("{\"nftables\":[]}\n", $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileRulesets(): iterable
    {
        $head = "table inet fleet_isolation {}\ndelete table inet fleet_isolation\n";
        yield 'another table' => ["table inet filter {}\ndelete table inet fleet_isolation\ntable inet fleet_isolation {\n}\n"];
        yield 'flush' => [$head."flush ruleset\n"];
        yield 'include' => [$head."include \"/etc/passwd\"\n"];
        yield 'delete elsewhere' => [$head."delete table inet filter\n"];
        yield 'second table' => [$head."table inet fleet_isolation {\n}\ntable ip nat {\n}\n"];
        yield 'shell metacharacters' => [$head."table inet fleet_isolation {\n  chain x { `id` }\n}\n"];
        yield 'missing delete' => ["table inet fleet_isolation {\n}\n"];
        yield 'a very long line' => [$head.'table inet fleet_isolation {'."\n  # ".str_repeat('a', 400)."\n}\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileRulesets')]
    public function testItRefusesAnythingButTheRenderedTable(string $staged): void
    {
        [$status, , $err] = $this->apply($staged);

        self::assertSame(65, $status, $err);
        self::assertStringNotContainsString('nft -f', (string) file_get_contents($this->log), 'nothing was loaded');
        self::assertFileDoesNotExist($this->root.'/etc/ddev-fleet/fleet_isolation.nft');
    }

    public function testAFailedCheckLoadsNothing(): void
    {
        [$status, , $err] = $this->apply($this->ruleset(), 'case "$1" in -c) exit 1;; esac');

        self::assertSame(65, $status);
        self::assertStringContainsString('nothing was changed', $err);
        self::assertStringNotContainsString('nft -f', (string) file_get_contents($this->log));
        self::assertFileDoesNotExist($this->root.'/etc/ddev-fleet/fleet_isolation.nft');
    }

    public function testAFailedLoadKeepsThePreviousCopy(): void
    {
        file_put_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft', 'previous');

        [$status] = $this->apply($this->ruleset(), 'case "$1" in -f) exit 1;; esac');

        self::assertSame(1, $status);
        self::assertSame('previous', file_get_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft'));
    }
}
