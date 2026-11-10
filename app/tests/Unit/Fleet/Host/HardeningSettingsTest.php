<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\Provision\FirewallState;
use App\Fleet\Host\Provision\FirewallSwitch;
use App\Fleet\Host\Provision\HardeningSettings;
use App\Fleet\Host\Provision\HostVarsReader;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\HostVarsRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HardeningSettingsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-hardening-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testSplitListTakesLinesCommasAndSpacesDropsBlanksAndDuplicates(): void
    {
        self::assertSame(['a', 'b', 'c'], HardeningSettings::splitList("a\n\n b, c\r\na"));
        self::assertSame([], HardeningSettings::splitList(null));
    }

    public function testOffHasNothingToCheck(): void
    {
        self::assertSame([], HardeningSettings::off()->violations());
    }

    public function testValidSettings(): void
    {
        self::assertSame([], new HardeningSettings(true, ['admin', 'deploy'], ['203.0.113.10', '2001:db8::/32'], 10, false, 'admin')->violations());
    }

    /**
     * @return iterable<string, array{HardeningSettings, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no ssh account' => [new HardeningSettings(true, [], [], 10, false, 'admin'), 'sshUsers'];
        yield 'bad account name' => [new HardeningSettings(true, ['Admin;rm'], [], 10, false, 'admin'), 'sshUsers'];
        yield 'no invoking account' => [new HardeningSettings(true, ['admin'], [], 10, false, null), 'invokingUser'];
        yield 'invoking account locked out' => [new HardeningSettings(true, ['admin'], [], 10, false, 'other'), 'invokingUser'];
        yield 'bad ip' => [new HardeningSettings(true, ['admin'], ['999.1.1.1'], 10, false, 'admin'), 'trustedIps'];
        yield 'bad prefix' => [new HardeningSettings(true, ['admin'], ['10.0.0.0/33'], 10, false, 'admin'), 'trustedIps'];
        yield 'grace too short' => [new HardeningSettings(true, ['admin'], [], 1, false, 'admin'), 'graceMinutes'];
        yield 'grace too long' => [new HardeningSettings(true, ['admin'], [], 121, false, 'admin'), 'graceMinutes'];
    }

    #[DataProvider('invalid')]
    public function testViolations(HardeningSettings $settings, string $field): void
    {
        self::assertArrayHasKey($field, $settings->violations());
    }

    public function testReaderRoundTripsWhatTheRendererWrites(): void
    {
        file_put_contents($this->dir.'/vars.yml', new HostVarsRenderer()->render('fleet.example.com', null, false, true, ['admin', 'deploy'], ['203.0.113.10'], 25, true, 'admin'));
        $reader = new HostVarsReader($this->dir.'/vars.yml');

        $h = $reader->hardening();
        self::assertEquals(new HardeningSettings(true, ['admin', 'deploy'], ['203.0.113.10'], 25, true, 'admin'), $h);
        self::assertFalse($reader->shellProfile());
    }

    public function testReaderSeedsFromTheInstallersRecordWhenThereIsNoHostVarsFile(): void
    {
        file_put_contents($this->dir.'/hardening.conf', "ENABLED=1\nSSH_ALLOW_USERS=admin ops\nTRUSTED_IPS=10.0.0.1\nFIREWALL_GRACE_MINUTES=30\nSSH_PORTS=22\nHARDEN_TMP=1\n");
        $reader = new HostVarsReader($this->dir.'/missing.yml', $this->dir.'/hardening.conf');

        self::assertFalse($reader->exists());
        self::assertEquals(new HardeningSettings(true, ['admin', 'ops'], ['10.0.0.1'], 30, true), $reader->hardening());
        self::assertTrue($reader->shellProfile());
        self::assertEquals(HardeningSettings::off(), new HostVarsReader($this->dir.'/missing.yml', $this->dir.'/none')->hardening());
    }

    public function testTheRendererAddsTheInvokingAccountOnlyWhenGivenAndOnlyWhenHardened(): void
    {
        $renderer = new HostVarsRenderer();
        $plain = $renderer->render('fleet.example.com', null, true, true, ['admin']);

        self::assertStringNotContainsString('invoking', $plain);
        self::assertStringEndsWith("fleet_hardening_invoking_user: \"admin\"\n", $renderer->render('fleet.example.com', null, true, true, ['admin'], [], 10, false, 'admin'));
        self::assertStringNotContainsString('invoking', $renderer->render('fleet.example.com', null, true, false, [], [], 10, false, 'admin'));
        $this->expectException(\InvalidArgumentException::class);
        $renderer->render('fleet.example.com', null, true, true, ['admin'], [], 10, false, 'a"b');
    }

    public function testFirewallSwitchStates(): void
    {
        $runner = new RecordingCommandRunner();
        $switch = new FirewallSwitch($runner, $this->dir.'/hardening.conf', $this->dir.'/confirmed');
        self::assertSame(FirewallState::Off, $switch->state());

        file_put_contents($this->dir.'/hardening.conf', "ENABLED=1\n");
        $runner->expect(['systemctl', 'is-active'], new CommandResult(0));
        self::assertSame(FirewallState::Armed, $switch->state());

        $runner->expect(['systemctl', 'is-active'], new CommandResult(3));
        self::assertSame(FirewallState::Expired, $switch->state());

        touch($this->dir.'/confirmed');
        self::assertSame(FirewallState::Confirmed, $switch->state());
    }

    public function testFiresAtOnlyWhileArmedAndOnlyAPlainDate(): void
    {
        $runner = new RecordingCommandRunner();
        file_put_contents($this->dir.'/hardening.conf', "ENABLED=1\n");
        $switch = new FirewallSwitch($runner, $this->dir.'/hardening.conf', $this->dir.'/confirmed');

        $runner->expect(['systemctl', 'is-active'], new CommandResult(0));
        $runner->expect(['systemctl', 'is-active'], new CommandResult(0));
        $runner->expect(['systemctl', 'show'], new CommandResult(0, "Tue 2026-11-10 12:00:00 UTC\n"));
        self::assertSame('Tue 2026-11-10 12:00:00 UTC', $switch->firesAt());

        $runner->expect(['systemctl', 'is-active'], new CommandResult(0));
        $runner->expect(['systemctl', 'is-active'], new CommandResult(0));
        $runner->expect(['systemctl', 'show'], new CommandResult(0, "<script>x</script>\n"));
        self::assertNull($switch->firesAt());
    }
}
