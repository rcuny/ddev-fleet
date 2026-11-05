<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/** FLE-219: step 20 merges /etc/docker/daemon.json (network pools, live-restore, log rotation) and never clobbers it. */
final class InstallerDockerDaemonTest extends TestCase
{
    use InstallerHarness;

    private string $prefix = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Root ignores FLEET_ROOT_PREFIX by design; these tests run as an unprivileged user.');
        }
        $this->prefix = $this->scratch().'/root';
        mkdir($this->prefix.'/etc/docker', 0o700, true);
    }

    /** @return array{int, string, string} */
    private function configure(string $routes = '10.1.2.0/24 dev eth1', ?string $existing = null): array
    {
        if (null !== $existing) {
            file_put_contents($this->prefix.'/etc/docker/daemon.json', $existing);
        }
        $stubs = $this->stubs(['ip' => "printf '%s\\n' 'default via 192.0.2.1 dev eth0' ".escapeshellarg($routes)]);

        return $this->withCommon(
            '. "$INSTALL_DIR/steps/20-docker.sh"; docker_daemon_config',
            ['FLEET_ROOT_PREFIX' => $this->prefix],
            $stubs,
        );
    }

    /** @return array<string, mixed> */
    private function daemon(): array
    {
        $json = json_decode((string) file_get_contents($this->prefix.'/etc/docker/daemon.json'), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($json));

        return $json;
    }

    public function testFreshFileGetsPoolsLiveRestoreAndLogRotation(): void
    {
        [$status, , $err] = $this->configure();

        self::assertSame(0, $status, $err);
        $daemon = $this->daemon();
        self::assertTrue($daemon['live-restore']);
        self::assertSame('json-file', $daemon['log-driver']);
        $pools = $daemon['default-address-pools'];
        self::assertSame(['base' => '172.17.0.0/16', 'size' => 16], $pools[0], 'the built-in pools stay first');
        self::assertSame(['base' => '192.168.0.0/16', 'size' => 20], $pools[15]);
        self::assertSame(['base' => '10.64.0.0/10', 'size' => 24], $pools[16]);
    }

    public function testOperatorKeysSurviveAndSetKeysAreKept(): void
    {
        [$status, , $err] = $this->configure(existing: '{"live-restore": false, "log-driver": "local", "dns": ["9.9.9.9"]}');

        self::assertSame(0, $status, $err);
        $daemon = $this->daemon();
        self::assertFalse($daemon['live-restore']);
        self::assertSame('local', $daemon['log-driver']);
        self::assertSame(['9.9.9.9'], $daemon['dns']);
        self::assertArrayNotHasKey('log-opts', $daemon);
        self::assertSame('10.64.0.0/10', $daemon['default-address-pools'][16]['base']);
    }

    public function testAnOperatorPoolIsLeftAlone(): void
    {
        $pools = '{"default-address-pools":[{"base":"10.200.0.0/16","size":24}]}';
        [$status, , $err] = $this->configure(existing: $pools);

        self::assertSame(0, $status, $err);
        self::assertSame([['base' => '10.200.0.0/16', 'size' => 24]], $this->daemon()['default-address-pools']);
        self::assertStringContainsString('already sets default-address-pools', $err);
    }

    public function testARoutedRangeSkipsThePool(): void
    {
        [$status, , $err] = $this->configure('10.0.0.0/8 via 192.0.2.1 dev eth0');

        self::assertSame(0, $status, $err);
        self::assertArrayNotHasKey('default-address-pools', $this->daemon());
        self::assertTrue($this->daemon()['live-restore']);
        self::assertStringContainsString('routes 10.64.0.0/10', $err);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->configure();
        $first = (string) file_get_contents($this->prefix.'/etc/docker/daemon.json');
        [$status] = $this->configure();

        self::assertSame(0, $status);
        self::assertSame($first, (string) file_get_contents($this->prefix.'/etc/docker/daemon.json'));
    }

    public function testBrokenJsonIsLeftUntouched(): void
    {
        [$status, , $err] = $this->configure(existing: '{not json');

        self::assertSame(0, $status, $err);
        self::assertSame('{not json', (string) file_get_contents($this->prefix.'/etc/docker/daemon.json'));
        self::assertStringContainsString('not a JSON object', $err);
    }

    public function testThePlanMentionsTheDaemonConfig(): void
    {
        [, $out] = $this->dryRun();

        self::assertStringContainsString('+ merge /etc/docker/daemon.json', $out);
    }
}
