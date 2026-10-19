<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\System\ApplyTarget;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\PrivilegedApply;
use App\Setup\SetupFinisher;
use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The files under install/etc (pool, php.ini, systemd units, Mercure and sudoers configuration), checked against the
 * app code that has to agree with them, and against the real hub / visudo when they are installed.
 */
final class UnitsTest extends TestCase
{
    use InstallerHarness;

    private const string SUDOERS_LINE = 'fleet ALL=(root) NOPASSWD: /usr/local/libexec/fleet/apply caddy, /usr/local/libexec/fleet/apply authelia';

    public function testPoolSocketMatchesRenderer(): void
    {
        $pool = $this->ini('php-fpm-fleet.conf');

        self::assertSame(CaddyfileRenderer::PHP_SOCKET, $pool['listen']);
        self::assertSame('/run/php/fleet.sock', $pool['listen']);
        self::assertSame('fleet', $pool['listen.owner']);
        self::assertSame('caddy', $pool['listen.group'], 'Caddy connects as its own user');
        self::assertSame('0660', $pool['listen.mode']);
        self::assertSame('fleet', $pool['user']);
        self::assertSame('fleet', $pool['group']);
    }

    public function testPoolProcessManagerAndLimits(): void
    {
        $pool = $this->ini('php-fpm-fleet.conf');

        self::assertSame('dynamic', $pool['pm']);
        self::assertSame('16', $pool['pm.max_children']);
        self::assertSame('2', $pool['pm.start_servers']);
        self::assertSame('2', $pool['pm.min_spare_servers']);
        self::assertSame('4', $pool['pm.max_spare_servers']);
        self::assertSame('500', $pool['pm.max_requests']);
        self::assertSame('yes', $pool['catch_workers_output']);
        self::assertSame('no', $pool['clear_env']);
        self::assertSame('prod', $pool['env[APP_ENV]']);
        self::assertSame('/home/fleet', $pool['env[HOME]']);
    }

    public function testPoolOutlastsTheSetupFinishRequest(): void
    {
        $pool = $this->ini('php-fpm-fleet.conf');

        // The Finish request is the longest the app serves (two helper runs, two validations and reloads).
        self::assertGreaterThanOrEqual(SetupFinisher::REQUIRED_REQUEST_TIMEOUT, (int) $pool['request_terminate_timeout']);
        self::assertGreaterThanOrEqual(SetupFinisher::REQUIRED_REQUEST_TIMEOUT, (int) $pool['php_admin_value[max_execution_time]']);
        self::assertGreaterThanOrEqual(240, SetupFinisher::REQUIRED_REQUEST_TIMEOUT);
    }

    public function testCaddyPutsNoShorterTimeoutOnTheAppThanTheFinishRequestNeeds(): void
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/system/Caddyfile.twig');

        // Caddy's php_fastcgi has no read timeout unless one is written; if one is ever added it must not cut Finish.
        if (1 === preg_match_all('/\b(read_timeout|write_timeout|timeouts?)\b\s+(\d+)(s|m)?/', $template, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $seconds = (int) $match[2] * ('m' === ($match[3] ?? 's') ? 60 : 1);
                self::assertGreaterThanOrEqual(SetupFinisher::REQUIRED_REQUEST_TIMEOUT, $seconds, $match[0]);
            }
        }
        self::addToAssertionCount(1);
    }

    public function testPhpIniSettings(): void
    {
        $ini = $this->ini('php-fleet.ini');

        self::assertSame('0', $ini['expose_php']);
        self::assertSame('1', $ini['opcache.enable']);
        self::assertSame('0', $ini['opcache.validate_timestamps'], 'code changes only through the installer, which reloads php-fpm');
        self::assertSame('128', $ini['opcache.memory_consumption']);
    }

    public function testPhpFpmDropInLoadsTheEnvironmentAndRelaxesTheVendorSandbox(): void
    {
        $unit = $this->unit('php-fpm-env.conf');

        self::assertSame(['/etc/ddev-fleet/fleet.env'], $unit['Service']['EnvironmentFile']);
        // Debian's unit has ProtectSystem=full: /etc is read-only for the pool and the sudo child unless listed here.
        self::assertSame(['/etc/caddy /etc/authelia'], $unit['Service']['ReadWritePaths']);
        self::assertSame(['no'], $unit['Service']['NoNewPrivileges'], 'the pool calls sudo');
    }

    public function testWorkerUnitDirectives(): void
    {
        $unit = $this->unit('fleet-worker@.service');
        $service = $unit['Service'];

        self::assertSame(['fleet'], $service['User']);
        self::assertSame(['/etc/ddev-fleet/fleet.env'], $service['EnvironmentFile']);
        self::assertSame(['HOME=/home/fleet'], $service['Environment']);
        self::assertSame(['/usr/bin/php8.4 /opt/ddev-fleet/app/bin/console messenger:consume jobs --time-limit=3600 --memory-limit=256M -vv'], $service['ExecStart']);
        self::assertSame(['always'], $service['Restart']);
        self::assertSame(['5'], $service['RestartSec']);
        self::assertSame(['full'], $service['ProtectSystem']);
        self::assertSame(['/srv/fleet /etc/caddy/fleet /home/fleet /opt/ddev-fleet/app/var'], $service['ReadWritePaths']);
        self::assertSame(['/opt/ddev-fleet/app'], $service['WorkingDirectory']);
        self::assertSame(['multi-user.target'], $unit['Install']['WantedBy']);

        // memory fleet-daemon-systemd-sandbox: each of these broke a real workload.
        foreach (['NoNewPrivileges', 'PrivateTmp', 'ProtectHome', 'ProcSubset', 'PrivateUsers', 'UMask'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $service, $forbidden.' must not be set on the worker');
        }
    }

    public function testWorkerCanWriteEverythingTheAppWrites(): void
    {
        $paths = explode(' ', $this->unit('fleet-worker@.service')['Service']['ReadWritePaths'][0]);

        // FLEET_HOME (db, keys, instances), the Caddy snippets (gate.conf), DDEV's state, the prod cache and logs.
        foreach (['/srv/fleet', '/etc/caddy/fleet', '/home/fleet', '/opt/ddev-fleet/app/var'] as $path) {
            self::assertContains($path, $paths);
        }
    }

    public function testMercureUnitDirectives(): void
    {
        $unit = $this->unit('fleet-mercure.service');
        $service = $unit['Service'];

        self::assertSame(['yes'], $service['DynamicUser']);
        self::assertSame(['fleet-mercure'], $service['StateDirectory']);
        self::assertSame(['XDG_DATA_HOME=/var/lib/fleet-mercure XDG_CONFIG_HOME=/var/lib/fleet-mercure'], $service['Environment']);
        self::assertSame(['/etc/ddev-fleet/mercure.env'], $service['EnvironmentFile']);
        self::assertSame(['always'], $service['Restart']);
        self::assertSame(['yes'], $service['NoNewPrivileges']);
        self::assertSame(['strict'], $service['ProtectSystem']);
        self::assertSame(['yes'], $service['PrivateTmp']);

        $exec = $service['ExecStart'][0];
        self::assertMatchesRegularExpression('~^/usr/local/bin/mercure run --config (\S+) --adapter caddyfile$~', $exec);
        $config = $this->capture('~--config (\S+)~', $exec);
        // A dynamic user is in no group: /etc/ddev-fleet is root:fleet 0750, so the hub's configuration cannot live there.
        self::assertStringStartsNotWith('/etc/ddev-fleet/', $config);
        self::assertSame('/etc/fleet-mercure/Caddyfile', $config);
    }

    public function testMercureCaddyfile(): void
    {
        $caddyfile = (string) file_get_contents(self::installDir().'/etc/mercure.Caddyfile');

        self::assertStringContainsString('http://'.CaddyfileRenderer::HUB.' {', $caddyfile, 'the app and Caddy talk to this address');
        self::assertStringContainsString('bind 127.0.0.1', $caddyfile);
        foreach (['admin off', 'auto_https off', 'http_port 3000', 'respond /healthz "ok" 200', 'protocol_version_compatibility 8', 'publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY} HS256', 'subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} HS256', 'respond 404'] as $directive) {
            self::assertStringContainsString($directive, $caddyfile);
        }
    }

    public function testMercureCaddyfileValidatesWhenHubBinaryAvailable(): void
    {
        $bin = getenv('FLEET_MERCURE_BIN');
        if (!\is_string($bin) || '' === $bin || !is_executable($bin)) {
            self::markTestSkipped('Set FLEET_MERCURE_BIN to the Mercure hub binary (v1.1.2) to validate the hub configuration.');
        }
        $key = str_repeat('k', 40);
        $process = new Process([$bin, 'validate', '--config', self::installDir().'/etc/mercure.Caddyfile', '--adapter', 'caddyfile'], null, [
            'MERCURE_PUBLISHER_JWT_KEY' => $key,
            'MERCURE_SUBSCRIBER_JWT_KEY' => $key,
            'XDG_CONFIG_HOME' => $this->scratch(),
            'XDG_DATA_HOME' => $this->scratch(),
        ], null, 60);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertStringContainsString('Valid configuration', $process->getErrorOutput().$process->getOutput());
    }

    public function testSudoersIsOneLineAndValidWhenVisudoAvailable(): void
    {
        $path = self::installDir().'/etc/sudoers-fleet-apply';
        $lines = array_values(array_filter(
            file($path, \FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $l): bool => '' !== trim($l) && !str_starts_with(ltrim($l), '#'),
        ));

        self::assertSame([self::SUDOERS_LINE], $lines, 'exactly one effective line');

        $visudo = new Process(['visudo', '--version']);
        if (0 !== $visudo->run()) {
            self::markTestSkipped('visudo is not installed (apt-get install sudo).');
        }
        $check = new Process(['visudo', '-cf', $path]);
        $check->run();
        self::assertSame(0, $check->getExitCode(), $check->getErrorOutput().$check->getOutput());
    }

    public function testApplyTargetsAreTheSameInTheEnumTheSudoersLineAndTheHelper(): void
    {
        $enum = array_map(static fn (ApplyTarget $t): string => $t->value, ApplyTarget::cases());
        sort($enum);

        // sudoers: every "<helper> <target>" pair of the line
        preg_match_all('~'.preg_quote(PrivilegedApply::HELPER, '~').' (\S+?)(?:,|$)~', self::SUDOERS_LINE, $pairs);
        $sudoers = $pairs[1];
        sort($sudoers);

        // helper: the allowlist word list
        $helper = (string) file_get_contents(self::installDir().'/libexec/apply');
        $targets = explode(' ', $this->capture("/^ALLOWED_TARGETS='([a-z ]+)'$/m", $helper));
        sort($targets);

        self::assertSame($enum, $sudoers, 'ApplyTarget vs the sudoers line');
        self::assertSame($enum, $targets, 'ApplyTarget vs the helper allowlist');

        // The file on disk is the line the test constant holds, and the helper lands where the app calls it.
        self::assertStringContainsString(self::SUDOERS_LINE, (string) file_get_contents(self::installDir().'/etc/sudoers-fleet-apply'));
        self::assertSame('/usr/local/libexec/fleet/apply', PrivilegedApply::HELPER);
        self::assertStringContainsString('HELPER_DIR=/usr/local/libexec/fleet', (string) file_get_contents(self::installDir().'/lib/common.sh'));
        self::assertStringContainsString('"$HELPER_DIR/apply"', (string) file_get_contents(self::installDir().'/steps/90-privileged.sh'));
    }

    public function testHelperStagingFilesAreTheOnesTheAppStages(): void
    {
        $helper = (string) file_get_contents(self::installDir().'/libexec/apply');
        $renderer = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Fleet/System/SystemRenderer.php');

        self::assertStringContainsString("'caddy' => ['Caddyfile', 'env']", $renderer);
        self::assertStringContainsString("'authelia' => ['configuration.yml']", $renderer);
        self::assertStringContainsString('stage_in Caddyfile', $helper);
        self::assertStringContainsString('stage_in env', $helper);
        self::assertStringContainsString('stage_in configuration.yml', $helper);

        // The env keys the helper accepts are exactly the ones CaddyEnvRenderer writes.
        $envRenderer = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Fleet/System/CaddyEnvRenderer.php');
        foreach (explode(' ', $this->capture("/CADDY_ENV_KEYS='([A-Z_ ]+)'/", $helper)) as $key) {
            self::assertStringContainsString($key, $envRenderer, $key);
        }
    }

    public function testAutheliaSecretFileVariablesMatchTheUnitDropIn(): void
    {
        $helper = (string) file_get_contents(self::installDir().'/libexec/apply');
        $dropIn = (string) file_get_contents(self::installDir().'/etc/authelia-secrets.conf');

        preg_match_all('/^Environment=(AUTHELIA_\w+_FILE=\S+)$/m', $dropIn, $fromDropIn);
        self::assertCount(3, $fromDropIn[1]);
        foreach ($fromDropIn[1] as $assignment) {
            self::assertStringContainsString($assignment, $helper, 'the validator must see the unit\'s secret file variables');
        }
    }

    public function testAutheliaTmpfilesMaskPresent(): void
    {
        $mask = self::installDir().'/etc/authelia-tmpfiles.conf';

        self::assertFileExists($mask);
        self::assertStringContainsString('/etc/tmpfiles.d/authelia.conf', (string) file_get_contents(self::installDir().'/steps/60-authelia.sh'));
    }

    public function testUnitFilesHoldNoSecrets(): void
    {
        foreach (glob(self::installDir().'/etc/*') ?: [] as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                $where = basename($file).':'.$number;
                self::assertDoesNotMatchRegularExpression('/[0-9a-f]{32,}/i', $line, $where.' holds a long hex string');
                if (1 === preg_match('/(SECRET|KEY|TOKEN|PASSWORD)[A-Z_]*\s*=\s*(\S+)/', $line, $m) && !str_contains($line, '_FILE=')) {
                    self::assertMatchesRegularExpression('~^(/|\{env\.)~', $m[2], $where.' assigns a secret-looking variable a literal value');
                }
            }
        }
    }

    public function testEveryUnitFileSectionIsWellFormed(): void
    {
        foreach (['php-fpm-env.conf', 'fleet-worker@.service', 'fleet-mercure.service', 'caddy-sandbox.conf', 'caddy-env.conf', 'authelia-secrets.conf'] as $file) {
            $unit = $this->unit($file);
            self::assertArrayHasKey('Service', $unit, $file);
        }
    }

    public function testNoWorldWritablePathsAreGrantedToServices(): void
    {
        foreach (['fleet-worker@.service', 'php-fpm-env.conf'] as $file) {
            foreach ($this->unit($file)['Service']['ReadWritePaths'] ?? [] as $value) {
                foreach (explode(' ', $value) as $path) {
                    self::assertNotContains($path, ['/', '/etc', '/usr', '/var', '/home', '/srv', '/opt'], $file);
                }
            }
        }
    }

    /** The first group of a pattern that must match. */
    private function capture(string $pattern, string $subject): string
    {
        self::assertSame(1, preg_match($pattern, $subject, $match), $pattern);

        return $match[1];
    }

    /**
     * @return array<string, string> key => value of a pool / php.ini file (comments and sections dropped)
     */
    private function ini(string $file): array
    {
        $values = [];
        foreach (file(self::installDir().'/etc/'.$file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, ';') || str_starts_with($line, '[')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @return array<string, array<string, list<string>>> section => directive => values (repeatable directives keep all)
     */
    private function unit(string $file): array
    {
        $section = '';
        $unit = [];
        foreach (file(self::installDir().'/etc/'.$file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (1 === preg_match('/^\[(\w+)\]$/', $line, $m)) {
                $section = $m[1];
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $unit[$section][$key][] = $value;
        }

        return $unit;
    }
}
