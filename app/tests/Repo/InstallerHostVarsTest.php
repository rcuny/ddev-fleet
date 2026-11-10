<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\System\HostVarsRenderer;
use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-258: the Ansible engine of the installer (--engine=ansible) and the host-vars.yml writer. The bash writer
 * (install/lib/host-vars.sh) and the app's HostVarsRenderer must produce the same bytes.
 */
final class InstallerHostVarsTest extends TestCase
{
    use InstallerHarness;

    /**
     * @param array<string, string> $env
     */
    private function render(string $script, array $env = []): string
    {
        $prefix = $this->scratch();
        $process = new Process(['bash', '-c', 'set -Eeuo pipefail; . "$1/lib/common.sh"; . "$1/lib/host-vars.sh"; shift; '.$script, 'x', self::installDir()], null, ['FLEET_ROOT_PREFIX' => $prefix] + $env);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    }

    public function testPlainInstallMatchesTheAppRenderer(): void
    {
        $bash = $this->render('DOMAIN=fleet.example.com ACME_EMAIL=ops@example.com; host_vars_resolve; host_vars_render');

        self::assertSame($bash, new HostVarsRenderer()->render('fleet.example.com', 'ops@example.com', true, false));
        self::assertStringContainsString("fleet_hardening_enabled: false\n", $bash);
    }

    public function testNoShellProfileAndNoEmail(): void
    {
        $bash = $this->render('DOMAIN=fleet.example.com SHELL_PROFILE=0; host_vars_resolve; host_vars_render');

        self::assertSame($bash, new HostVarsRenderer()->render('fleet.example.com', null, false, false));
        self::assertStringContainsString("fleet_acme_email: \"\"\n", $bash);
    }

    public function testHardeningFlagsMatchTheAppRenderer(): void
    {
        $bash = $this->render('DOMAIN=fleet.example.com HARDEN=1 HARDEN_SSH_USERS=(admin ops) HARDEN_TRUSTED_IPS=(203.0.113.10 2001:db8::/32) HARDEN_GRACE=15 HARDEN_TMP=1; host_vars_resolve; host_vars_render');

        self::assertSame($bash, new HostVarsRenderer()->render('fleet.example.com', null, true, true, ['admin', 'ops'], ['203.0.113.10', '2001:db8::/32'], 15, true));
    }

    public function testBareHardenKeepsWhatAnEarlierRunStored(): void
    {
        $etc = $this->scratch().'/etc/ddev-fleet';
        mkdir($etc, 0o755, true);
        file_put_contents($etc.'/hardening.conf', "# comment\nENABLED=1\nSSH_ALLOW_USERS=admin\nTRUSTED_IPS=203.0.113.10 198.51.100.0/24\nFIREWALL_GRACE_MINUTES=20\nSSH_PORTS=22\nHARDEN_TMP=0\n");

        // No --harden at all: the stored ENABLED=1 keeps hardening on; --harden alone gives the same result.
        $stored = $this->render('DOMAIN=fleet.example.com; host_vars_resolve; host_vars_render');
        $explicit = $this->render('DOMAIN=fleet.example.com HARDEN=1; host_vars_resolve; host_vars_render');

        self::assertSame($stored, $explicit);
        self::assertSame($stored, new HostVarsRenderer()->render('fleet.example.com', null, true, true, ['admin'], ['203.0.113.10', '198.51.100.0/24'], 20, false));
    }

    public function testAStoredValueThatParseArgsWouldRefuseStopsTheWriter(): void
    {
        $etc = $this->scratch().'/etc/ddev-fleet';
        mkdir($etc, 0o755, true);
        file_put_contents($etc.'/hardening.conf', "ENABLED=1\nSSH_ALLOW_USERS=ad\"min\n");
        $process = new Process(['bash', '-c', '. "$1/lib/common.sh"; . "$1/lib/host-vars.sh"; DOMAIN=fleet.example.com; host_vars_resolve', 'x', self::installDir()], null, ['FLEET_ROOT_PREFIX' => $this->scratch()]);
        $process->run();

        self::assertSame(64, $process->getExitCode());
    }

    public function testRendererRefusesUnsafeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HostVarsRenderer()->render('fleet.example.com', null, true, true, ["root\nfleet_x: 1"]);
    }

    public function testAnsibleDryRunPlansPackagesHostVarsAndPlaybookAndNoStep(): void
    {
        $process = new Process(['bash', self::installDir().'/run.sh', '--dry-run', '--engine=ansible', '--domain=fleet.example.com', '--skip-dns-check'], null, ['FLEET_OS_RELEASE' => $this->osRelease()]);
        $process->run();
        $out = $process->getOutput();

        self::assertSame(0, $process->getExitCode(), $out.$process->getErrorOutput());
        self::assertStringContainsString('install ansible-core python3-apt', $out);
        self::assertStringContainsString('+ write /etc/ddev-fleet/host-vars.yml 0644 root:root', $out);
        self::assertMatchesRegularExpression('~ansible-playbook -i localhost\\\\, -c local \S+/ansible/site\.yml -e fleet_ref=main -e fleet_hardening_strict=false~', $out);
        self::assertStringNotContainsString('==> 10-packages', $out);
    }

    public function testExplicitHardenIsStrictAndTheBashEngineStaysTheDefault(): void
    {
        $env = ['FLEET_OS_RELEASE' => $this->osRelease()];
        $strict = new Process(['bash', self::installDir().'/run.sh', '--dry-run', '--engine=ansible', '--domain=fleet.example.com', '--skip-dns-check', '--harden', '--ssh-allow-user=admin'], null, $env);
        $strict->run();
        self::assertStringContainsString('fleet_hardening_strict=true', $strict->getOutput());

        $default = new Process(['bash', self::installDir().'/run.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'], null, $env);
        $default->run();
        self::assertStringContainsString('==> 10-packages', $default->getOutput());
        self::assertStringNotContainsString('ansible-playbook', $default->getOutput());
    }

    public function testUnknownEngineIsAUsageErrorInBothStages(): void
    {
        foreach (['install.sh', 'run.sh'] as $script) {
            $process = new Process(['bash', self::installDir().'/'.$script, '--dry-run', '--engine=puppet', '--domain=fleet.example.com'], null, ['FLEET_OS_RELEASE' => $this->osRelease()]);
            $process->run();
            self::assertSame(64, $process->getExitCode(), $script);
        }
    }

    public function testPreflightAcceptsTheEngineFlag(): void
    {
        $process = new Process(['bash', self::installDir().'/run.sh', '--preflight', '--engine=ansible', '--domain=fleet.example.com'], null, ['FLEET_OS_RELEASE' => $this->osRelease()]);
        $process->run();

        self::assertNotSame(64, $process->getExitCode(), $process->getErrorOutput());
    }
}
