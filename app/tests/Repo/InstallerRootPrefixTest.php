<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * The FLEET_ROOT_PREFIX harness of install/lib/common.sh: unprivileged, NON-dry runs of the installer's helpers and of
 * one real step against a scratch tree, with the external commands (curl, gpg, apt-get, runuser, ...) stubbed. The
 * dry-run tests elsewhere only prove the plan; these prove what the code does when it is not a dry-run.
 */
final class InstallerRootPrefixTest extends TestCase
{
    use InstallerHarness;

    private const string FINGERPRINT = 'DBD730A3A581BDAC98BAF17FC16711FC1179C7CA';

    private string $prefix = '';
    private string $log = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Root ignores FLEET_ROOT_PREFIX by design; these tests run as an unprivileged user.');
        }
        $this->prefix = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        mkdir($this->prefix, 0o700, true);
        touch($this->log);
    }

    public function testThePathsMoveUnderThePrefix(): void
    {
        [$status, $out, $err] = $this->withCommon(
            'printf "%s\n" "$KEYRING_DIR" "$FLEET_SRV_DIR" "$HELPER_DIR" "$INSTALL_LOG"',
            ['FLEET_ROOT_PREFIX' => $this->prefix.'/'],
        );

        self::assertSame(0, $status, $err);
        self::assertSame(
            [$this->prefix.'/etc/apt/keyrings', $this->prefix.'/srv/fleet', $this->prefix.'/usr/local/libexec/fleet', $this->prefix.'/var/log/ddev-fleet-install.log'],
            explode("\n", trim($out)),
        );
    }

    public function testARelativePrefixIsRefused(): void
    {
        [$status, , $err] = $this->withCommon('true', ['FLEET_ROOT_PREFIX' => 'relative/dir']);

        self::assertSame(64, $status);
        self::assertStringContainsString('must be an absolute path', $err);
    }

    public function testWithoutThePrefixTheRealPathsStay(): void
    {
        [$status, $out, $err] = $this->withCommon('printf "%s" "$KEYRING_DIR"');

        self::assertSame(0, $status, $err);
        self::assertSame('/etc/apt/keyrings', $out);
    }

    public function testFilesAreWrittenForRealAndIdempotently(): void
    {
        $code = 'ensure_dir "$FLEET_ETC_DIR" 0750 root:fleet; '
            .'write_file "$FLEET_ETC_DIR/a.env" 0640 root:fleet <<<"one"; echo "first=$FILE_CHANGED"; '
            .'write_file "$FLEET_ETC_DIR/a.env" 0640 root:fleet <<<"one"; echo "again=$FILE_CHANGED"; '
            .'write_file "$FLEET_ETC_DIR/a.env" 0640 root:fleet <<<"two"; echo "changed=$FILE_CHANGED"';

        [$status, $out, $err] = $this->withCommon($code, ['FLEET_ROOT_PREFIX' => $this->prefix]);

        self::assertSame(0, $status, $err);
        self::assertSame("first=1\nagain=0\nchanged=1\n", $out);
        self::assertSame("two\n", file_get_contents($this->prefix.'/etc/ddev-fleet/a.env'));
        self::assertSame(0o640, fileperms($this->prefix.'/etc/ddev-fleet/a.env') & 0o777);
        self::assertSame(0o750, fileperms($this->prefix.'/etc/ddev-fleet') & 0o777);
    }

    public function testAKeyWithThePinnedFingerprintIsInstalled(): void
    {
        [$status, , $err] = $this->aptRepo(self::FINGERPRINT);

        self::assertSame(0, $status, $err);
        self::assertSame("KEYDATA\n", file_get_contents($this->prefix.'/etc/apt/keyrings/ddev.asc'));
        self::assertFileExists($this->prefix.'/etc/apt/sources.list.d/ddev.list');
    }

    public function testAKeyWithAnotherFingerprintIsRefusedAndNothingIsInstalled(): void
    {
        [$status, , $err] = $this->aptRepo('0000000000000000000000000000000000000000');

        self::assertSame(1, $status);
        self::assertStringContainsString('refusing to trust it', $err);
        self::assertStringContainsString(self::FINGERPRINT, $err, 'the message names the fingerprint that was found');
        self::assertFileDoesNotExist($this->prefix.'/etc/apt/keyrings/ddev.asc');
        self::assertFileDoesNotExist($this->prefix.'/etc/apt/sources.list.d/ddev.list');
    }

    public function testAnUnreadableKeyIsRefused(): void
    {
        [$status, , $err] = $this->aptRepo(self::FINGERPRINT, gpgOutput: '');

        self::assertSame(1, $status);
        self::assertStringContainsString("fingerprint 'unreadable'", $err);
        self::assertFileDoesNotExist($this->prefix.'/etc/apt/keyrings/ddev.asc');
    }

    public function testOnlyThePrimaryKeyCountsNotASubkey(): void
    {
        // gpg lists the subkeys' fingerprints after the primary one: a vendor key whose SUBKEY matches is not pinned.
        $gpg = "pub:-:4096:1:AAAA:1:::-:::scESC:\nfpr:::::::::1111111111111111111111111111111111111111:\n"
            ."sub:-:4096:1:BBBB:1::::::e:\nfpr:::::::::".self::FINGERPRINT.':';

        [$status, , $err] = $this->aptRepo(self::FINGERPRINT, gpgOutput: $gpg);

        self::assertSame(1, $status);
        self::assertStringContainsString('refusing to trust it', $err);
    }

    public function testStepFortyRunsForRealAgainstTheStubs(): void
    {
        $stubs = $this->commonStubs();
        $code = 'mkdir -p "$APT_SOURCES_DIR"; DPKG_UPDATES_DIR="$FLEET_ROOT_PREFIX/none"; . "$INSTALL_DIR/steps/40-ddev.sh"; step_main';

        [$status, , $err] = $this->withCommon($code, ['FLEET_ROOT_PREFIX' => $this->prefix], $stubs);

        self::assertSame(0, $status, $err);
        $calls = (string) file_get_contents($this->log);
        self::assertStringContainsString('apt-get -y -q', $calls);
        self::assertStringContainsString(' update', $calls, 'a new source triggers apt-get update');
        self::assertMatchesRegularExpression('/apt-get .* install ddev\n/', $calls);
        self::assertStringContainsString('--router-bind-all-interfaces=false', $calls, 'the router is bound explicitly');
        self::assertStringContainsString('mkcert -install', $calls);
        self::assertStringContainsString('signed-by='.$this->prefix.'/etc/apt/keyrings/ddev.asc', (string) file_get_contents($this->prefix.'/etc/apt/sources.list.d/ddev.list'));
    }

    public function testAnInstallDirOthersCanChangeIsRefused(): void
    {
        // The checkout of this test run belongs to the unprivileged user, not to root: exactly what must be refused.
        [$status, , $err] = $this->withCommon('check_install_dir', ['FLEET_DRY_RUN' => '0']);

        self::assertSame(64, $status);
        self::assertStringContainsString('not owned by root or is writable by others', $err);
    }

    public function testADryRunMayStartFromAnyCheckout(): void
    {
        [$status, , $err] = $this->withCommon('check_install_dir', ['FLEET_DRY_RUN' => '1']);

        self::assertSame(0, $status, $err);
    }

    public function testRunShChecksTheInstallDirBeforeAnyStep(): void
    {
        $source = (string) file_get_contents(self::installDir().'/run.sh');

        self::assertLessThan(strpos($source, 'for step in'), strpos($source, 'check_install_dir'));
        self::assertLessThan(strpos($source, 'check_install_dir'), strpos($source, 'check_root'));
    }

    /**
     * @return array{int, string, string}
     */
    private function aptRepo(string $fingerprint, ?string $gpgOutput = null): array
    {
        $gpgOutput ??= "pub:-:4096:1:C16711FC1179C7CA:1657056143:::-:::scESC:\nfpr:::::::::".self::FINGERPRINT.":\nsub:-:4096:1:645F68BB6733F77B:1657056143::::::e:\nfpr:::::::::305D8FD1875C3E79906A42BC645F68BB6733F77B:";
        $stubs = $this->stubs([
            // curl -fsSL ... -o <out> <url>: writes the "key".
            'curl' => 'while [ "$#" -gt 0 ]; do [ "$1" = -o ] && out=$2; shift; done; echo KEYDATA > "$out"',
            'gpg' => 'cat <<\'EOT\'
'.$gpgOutput."\nEOT",
        ]);
        $code = 'mkdir -p "$APT_SOURCES_DIR"; apt_repo ddev https://pkg.example.com/gpg.key "$KEYRING_DIR/ddev.asc" "deb [signed-by=$KEYRING_DIR/ddev.asc] https://pkg.example.com/ * *" '.escapeshellarg($fingerprint);

        return $this->withCommon($code, ['FLEET_ROOT_PREFIX' => $this->prefix], $stubs);
    }

    private function commonStubs(): string
    {
        $log = $this->log;
        $record = 'echo "$(basename "$0") $*" >> '.$log;

        return $this->stubs([
            'curl' => 'while [ "$#" -gt 0 ]; do [ "$1" = -o ] && out=$2; shift; done; echo KEYDATA > "$out"',
            'gpg' => 'echo "pub:-:4096:1:C16711FC1179C7CA:1:::-:::scESC:"; echo "fpr:::::::::'.self::FINGERPRINT.':"',
            'apt-get' => $record,
            'dpkg' => 'exit 0',
            'runuser' => $record,
        ]);
    }
}
