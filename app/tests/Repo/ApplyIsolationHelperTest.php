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
        mkdir($this->root.'/proc/sys/net/bridge', 0o700, true);
        file_put_contents($this->root.'/proc/sys/net/bridge/bridge-nf-call-iptables', "1\n");
        file_put_contents($this->root.'/proc/sys/net/bridge/bridge-nf-call-ip6tables', "1\n");
    }

    /**
     * @return array{int, string, string}
     */
    private function apply(?string $staged, string $nft = 'exit 0'): array
    {
        if (null !== $staged) {
            file_put_contents($this->root.'/srv/fleet/staging/isolation/fleet_isolation.nft', $staged);
        }
        $stubs = $this->stubs(['nft' => "echo \"nft \$*\" >> {$this->log}\n{$nft}", 'modprobe' => "echo \"modprobe \$*\" >> {$this->log}", 'sysctl' => "echo \"sysctl \$*\" >> {$this->log}"]);
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
        self::assertSame(['modprobe br_netfilter', 'sysctl -q -w net.bridge.bridge-nf-call-iptables=1 net.bridge.bridge-nf-call-ip6tables=1'], \array_slice($calls, 0, 2));
        $calls = \array_slice($calls, 2);
        self::assertCount(2, $calls);
        self::assertMatchesRegularExpression('~^nft -c -f \S+/fleet_isolation\.nft$~', $calls[0]);
        self::assertMatchesRegularExpression('~^nft -f \S+/fleet_isolation\.nft$~', $calls[1]);
        self::assertSame($this->ruleset(), file_get_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft'));
        self::assertFileDoesNotExist($this->root.'/srv/fleet/staging/isolation/fleet_isolation.nft', 'the staged file is consumed');
        // br_netfilter and its sysctls survive a reboot.
        self::assertStringContainsString("br_netfilter\n", (string) file_get_contents($this->root.'/etc/modules-load.d/fleet-isolation.conf'));
        self::assertStringContainsString('net.bridge.bridge-nf-call-iptables = 1', (string) file_get_contents($this->root.'/etc/sysctl.d/90-fleet-isolation.conf'));
    }

    public function testAnUnchangedRulesetWritesNothingUnderEtc(): void
    {
        $this->apply($this->ruleset());
        $files = [$this->root.'/etc/modules-load.d/fleet-isolation.conf', $this->root.'/etc/sysctl.d/90-fleet-isolation.conf'];
        foreach ($files as $file) {
            touch($file, 1_000_000_000);
            clearstatcache();
        }
        // Read-only directories (what the watcher's sandbox looks like): a rewrite would fail.
        foreach (['/etc/modules-load.d', '/etc/sysctl.d'] as $dir) {
            chmod($this->root.$dir, 0o555);
        }

        [$status, , $err] = $this->apply($this->ruleset());

        foreach (['/etc/modules-load.d', '/etc/sysctl.d'] as $dir) {
            chmod($this->root.$dir, 0o755);
        }
        clearstatcache();
        self::assertSame(0, $status, $err);
        foreach ($files as $file) {
            self::assertSame(1_000_000_000, filemtime($file), 'a pass that changes nothing never rewrites the files');
        }
    }

    public function testAFailedFileWriteLeavesTheTableUntouched(): void
    {
        mkdir($this->root.'/etc/modules-load.d', 0o755, true);
        chmod($this->root.'/etc/modules-load.d', 0o555);

        [$status, , $err] = $this->apply($this->ruleset());

        chmod($this->root.'/etc/modules-load.d', 0o755);
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root ignores directory modes');
        }
        self::assertNotSame(0, $status);
        self::assertStringContainsString('nothing was changed', $err);
        $calls = (string) file_get_contents($this->log);
        self::assertStringNotContainsString('nft -f ', $calls, 'nft -f never ran');
    }

    public function testTheDisableFormRemovesTheCopyAndDeletesTheTable(): void
    {
        file_put_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft', 'old');

        [$status, $out] = $this->apply(IsolationRuleset::disabled());

        self::assertSame(0, $status);
        self::assertStringContainsString('isolation table removed', $out);
        self::assertFileDoesNotExist($this->root.'/etc/ddev-fleet/fleet_isolation.nft');
        self::assertFileDoesNotExist($this->root.'/etc/modules-load.d/fleet-isolation.conf');
        self::assertFileDoesNotExist($this->root.'/etc/sysctl.d/90-fleet-isolation.conf');
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
        yield 'a bare chain line outside the table' => [$head."chain forward { type filter hook forward priority 0; }\n"];
        yield 'a bare rule outside the table' => [$head."iifname \"br-0123456789ab\" drop\n"];
        yield 'a bare element line outside the table' => [$head."elements = { 1.2.3.4 }\n"];
        yield 'a rule against another table inside a chain' => [$head."table inet fleet_isolation {\n  chain forward {\n    type filter hook forward priority -10; policy accept;\n    iifname \"eth0\" oifname \"eth1\" ip saddr @routers accept\n    ip daddr 8.8.8.8 drop\n  }\n}\n"];
        yield 'a hook of another priority' => [$head."table inet fleet_isolation {\n  chain forward {\n    type filter hook prerouting priority -300; policy drop;\n  }\n}\n"];
        yield 'a jump to a foreign chain' => [$head."table inet fleet_isolation {\n  chain forward {\n    type filter hook forward priority -10; policy accept;\n    jump other\n  }\n}\n"];
        yield 'a second table block after the first' => [$head."table inet fleet_isolation {\n}\ntable inet fleet_isolation {\n}\n"];
        yield 'content after the closing brace' => [$head."table inet fleet_isolation {\n}\naccept\n"];
        yield 'an unterminated block' => [$head."table inet fleet_isolation {\n  chain forward {\n"];
        yield 'a set of an unknown name' => [$head."table inet fleet_isolation {\n  set evil {\n    type ipv4_addr;\n  }\n}\n"];
        yield 'an element line with a comment injected' => [$head."table inet fleet_isolation {\n  set routers {\n    type ipv4_addr;\n    elements = {\n      1.2.3.4; flush ruleset\n    }\n  }\n}\n"];
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

    public function testARulesetWithLinksAndEveryRenderedLineIsAccepted(): void
    {
        $link = new \App\Fleet\Isolation\ResolvedLink('app--staging', 'app', 'cms--staging', 'cms', 'typesense', 8108, null, 'ddev-app--staging-web', 'ddev-cms--staging-typesense')->withAddresses('172.20.0.2', '172.20.0.3');
        $ruleset = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', ['172.18.0.2'], [9108], [9003], [$link], [['172.18.0.5', '172.18.0.6', 80]], [['172.18.0.5', 22]]));

        [$status, , $err] = $this->apply($ruleset);

        self::assertSame(0, $status, $err);
        self::assertSame($ruleset, file_get_contents($this->root.'/etc/ddev-fleet/fleet_isolation.nft'));
    }

    public function testItRefusesToLoadWhileNftablesServiceIsEnabledButAlwaysAllowsTheDisableForm(): void
    {
        $stubs = $this->stubs(['nft' => "echo \"nft \$*\" >> {$this->log}", 'systemctl' => 'echo enabled']);
        $run = function (string $staged) use ($stubs): array {
            file_put_contents($this->root.'/srv/fleet/staging/isolation/fleet_isolation.nft', $staged);
            $process = new Process(['bash', self::installDir().'/libexec/apply', 'isolation'], null, ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')], null, 60);
            $process->run();

            return [(int) $process->getExitCode(), $process->getErrorOutput()];
        };

        [$status, $err] = $run($this->ruleset());
        self::assertSame(69, $status);
        self::assertStringContainsString('nftables.service', $err);
        self::assertStringNotContainsString('nft -f', (string) file_get_contents($this->log));
        [$status] = $run(IsolationRuleset::disabled());
        self::assertSame(0, $status, 'the disable form must always work');
    }

    public function testItRefusesWhenBridgeNetfilterIsNotActiveAndChangesNothing(): void
    {
        file_put_contents($this->root.'/proc/sys/net/bridge/bridge-nf-call-iptables', "0\n");

        [$status, , $err] = $this->apply($this->ruleset());

        self::assertSame(69, $status);
        self::assertStringContainsString('br_netfilter is not active', $err);
        self::assertStringNotContainsString('nft -f', (string) file_get_contents($this->log));
        self::assertFileDoesNotExist($this->root.'/etc/ddev-fleet/fleet_isolation.nft');
        self::assertFileDoesNotExist($this->root.'/etc/modules-load.d/fleet-isolation.conf');
        // The disable form never needs it.
        [$status] = $this->apply(IsolationRuleset::disabled());
        self::assertSame(0, $status);
    }

    public function testTheRecordedFlowsOfAManyFlowRulesetPassTheAllowlistOneElementPerLine(): void
    {
        $flows = [];
        for ($i = 0; $i < 500; ++$i) {
            $flows[] = ['172.18.'.intdiv($i, 250).'.'.(1 + $i % 250), '172.18.9.'.(1 + $i % 250), 80 + $i];
        }
        $ruleset = IsolationRuleset::render(new IsolationInput(IsolationMode::Report, 'br-0123456789ab', ['172.18.0.2'], [], [], [], $flows, [['172.18.0.5', 22]]));
        self::assertLessThan(300, max(array_map('strlen', explode("\n", $ruleset))), 'no line is long');

        [$status, , $err] = $this->apply($ruleset);

        self::assertSame(0, $status, $err);
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
