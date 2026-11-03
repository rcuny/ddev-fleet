<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * install/steps/70-env.sh run for real against a scratch directory (FLEET_ETC_DIR points at it, chown is stubbed so
 * the tests run unprivileged): the generated environment files, their modes, and what a re-run does to them.
 */
final class EnvFileTest extends TestCase
{
    use InstallerHarness;

    /** @var list<string> the keys of fleet.env, in file order */
    private const array KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'MERCURE_JWT_SECRET', 'FLEET_PROXY_SECRET', 'FLEET_HOME', 'FLEET_RUNNER',
        'FLEET_TRUSTED_PROXIES', 'FLEET_ADMIN_GROUP', 'FLEET_CADDY_DIR', 'FLEET_JOB_RETENTION_DAYS', 'DEFAULT_URI',
    ];

    public function testGeneratesAllKeysWithSecretsOf64HexChars(): void
    {
        $dir = $this->runStep();
        $env = $this->parse($dir.'/fleet.env');

        self::assertSame(self::KEYS, array_keys($env));
        foreach (['APP_SECRET', 'MERCURE_JWT_SECRET', 'FLEET_PROXY_SECRET'] as $secret) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env[$secret], $secret);
        }
        self::assertCount(3, array_unique([$env['APP_SECRET'], $env['MERCURE_JWT_SECRET'], $env['FLEET_PROXY_SECRET']]), 'three independent secrets');
        self::assertSame('prod', $env['APP_ENV']);
        self::assertSame('0', $env['APP_DEBUG']);
        self::assertSame('/srv/fleet', $env['FLEET_HOME']);
        self::assertSame('fleet-admins', $env['FLEET_ADMIN_GROUP']);
        self::assertSame('/etc/caddy/fleet', $env['FLEET_CADDY_DIR']);
        self::assertSame('https://fleet.example.com', $env['DEFAULT_URI']);
    }

    public function testModes(): void
    {
        $dir = $this->runStep();

        self::assertSame(0o640, fileperms($dir.'/fleet.env') & 0o777, 'root:fleet 0640: the app reads it, others do not');
        self::assertSame(0o600, fileperms($dir.'/mercure.env') & 0o777, 'root:root 0600: only systemd reads it');
        self::assertSame(0o750, fileperms($dir) & 0o777);
        self::assertSame([], glob($dir.'/*.??????') ?: [], 'no temp files left behind');
    }

    public function testChownTargetsAreRootFleetAndRootRoot(): void
    {
        $chownLog = $this->scratch().'/chown.log';
        $stubs = $this->stubs(['chown' => 'echo "$@" >> '.escapeshellarg($chownLog)]);

        $this->runStep($stubs);

        $calls = (string) file_get_contents($chownLog);
        self::assertMatchesRegularExpression('~root:fleet \S+/fleet\.env~', $calls);
        self::assertMatchesRegularExpression('~root:root \S+/mercure\.env~', $calls);
        self::assertMatchesRegularExpression('~root:fleet \S+/etc-ddev-fleet$~m', $calls, 'the directory is root:fleet 0750');
    }

    public function testIdempotentKeepsExistingSecrets(): void
    {
        $dir = $this->runStep();
        $first = (string) file_get_contents($dir.'/fleet.env');
        $firstMercure = (string) file_get_contents($dir.'/mercure.env');

        $this->runStep();

        self::assertSame($first, file_get_contents($dir.'/fleet.env'), 'a re-run (an upgrade) never regenerates a secret');
        self::assertSame($firstMercure, file_get_contents($dir.'/mercure.env'));
    }

    public function testAddsOnlyMissingKeys(): void
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        mkdir($dir, 0o750);
        $custom = str_repeat('c', 64);
        file_put_contents($dir.'/fleet.env', "APP_ENV=prod\nAPP_SECRET={$custom}\nFLEET_RUNNER=process\nEXTRA_SETTING=kept\nFLEET_ADMIN_GROUP=my-admins\n");

        $this->runStep();
        $env = $this->parse($dir.'/fleet.env');

        self::assertSame($custom, $env['APP_SECRET'], 'an existing secret is never replaced');
        self::assertSame('my-admins', $env['FLEET_ADMIN_GROUP'], 'a value an operator set stays');
        self::assertSame('kept', $env['EXTRA_SETTING'], 'unknown keys stay');
        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $env);
        }
        self::assertSame(1, substr_count((string) file_get_contents($dir.'/fleet.env'), 'APP_ENV='), 'no duplicate of an existing key');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env['MERCURE_JWT_SECRET'], 'a missing secret is generated');
    }

    public function testEnvStepAddsRetentionOnlyWhenMissing(): void
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        mkdir($dir, 0o750);
        file_put_contents($dir.'/fleet.env', "APP_ENV=prod\nFLEET_JOB_RETENTION_DAYS=30\n");

        $this->runStep();
        self::assertSame('30', $this->parse($dir.'/fleet.env')['FLEET_JOB_RETENTION_DAYS'], 'an operator value stays');

        file_put_contents($dir.'/fleet.env', "APP_ENV=prod\n");
        $this->runStep();
        self::assertSame('90', $this->parse($dir.'/fleet.env')['FLEET_JOB_RETENTION_DAYS']);
    }

    public function testAnExistingEmptyValueCountsAsPresent(): void
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        mkdir($dir, 0o750);
        file_put_contents($dir.'/fleet.env', "FLEET_TRUSTED_PROXIES=\nAPP_DEBUG=\n");

        $this->runStep();
        $env = $this->parse($dir.'/fleet.env');

        self::assertSame('', $env['FLEET_TRUSTED_PROXIES']);
        self::assertSame('', $env['APP_DEBUG'], 'only missing keys are added, an empty value is not missing');
    }

    public function testTrustedProxiesEmptyAndRunnerProcess(): void
    {
        $dir = $this->runStep();
        $raw = (string) file_get_contents($dir.'/fleet.env');

        self::assertMatchesRegularExpression('/^FLEET_TRUSTED_PROXIES=$/m', $raw, 'explicitly empty: the committed .env default (127.0.0.1,::1) must not apply');
        self::assertMatchesRegularExpression('/^FLEET_RUNNER=process$/m', $raw);
        self::assertSame('/srv/fleet', $this->parse($dir.'/fleet.env')['FLEET_HOME'], 'state outside the code tree (the production guard refuses the dotenv default)');
    }

    public function testMercureEnvDerivesFromTheSameSecret(): void
    {
        $dir = $this->runStep();
        $env = $this->parse($dir.'/fleet.env');
        $mercure = $this->parse($dir.'/mercure.env');

        self::assertSame(['MERCURE_PUBLISHER_JWT_KEY', 'MERCURE_SUBSCRIBER_JWT_KEY'], array_keys($mercure));
        self::assertSame($env['MERCURE_JWT_SECRET'], $mercure['MERCURE_PUBLISHER_JWT_KEY']);
        self::assertSame($env['MERCURE_JWT_SECRET'], $mercure['MERCURE_SUBSCRIBER_JWT_KEY']);

        // If the secret in fleet.env is changed by hand, the next run re-derives the hub's keys from it.
        $new = str_repeat('d', 64);
        file_put_contents($dir.'/fleet.env', (string) preg_replace('/^MERCURE_JWT_SECRET=.*$/m', 'MERCURE_JWT_SECRET='.$new, (string) file_get_contents($dir.'/fleet.env')));
        $this->runStep();
        $mercure = $this->parse($dir.'/mercure.env');
        self::assertSame($new, $mercure['MERCURE_PUBLISHER_JWT_KEY']);
        self::assertSame($new, $mercure['MERCURE_SUBSCRIBER_JWT_KEY']);
    }

    public function testTheFileIsPlainKeyValueForSystemdAndTheShell(): void
    {
        $dir = $this->runStep();

        foreach (['fleet.env', 'mercure.env'] as $file) {
            foreach (explode("\n", rtrim((string) file_get_contents($dir.'/'.$file))) as $line) {
                if ('' === $line || str_starts_with($line, '#')) {
                    continue;
                }
                self::assertMatchesRegularExpression('~^[A-Z][A-Z_]*=[A-Za-z0-9._:/-]*$~', $line, $file.': unquoted, no spaces, nothing a shell would interpret');
            }
        }
        // Sourcing it (what the `fleet` wrapper does) yields the same values as the parser above.
        [$status, $out] = $this->bash(['-c', 'set -a; . '.escapeshellarg($dir.'/fleet.env').'; set +a; printf "%s|%s|%s" "$APP_ENV" "$FLEET_HOME" "${#FLEET_PROXY_SECRET}"']);
        self::assertSame(0, $status);
        self::assertSame('prod|/srv/fleet|64', $out);
    }

    public function testChangeFlagsFollowWhatTheStepActuallyChanged(): void
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        $flags = static fn (string $out): string => trim(substr($out, (int) strrpos($out, 'flags:')));

        [, $out] = $this->runStepRaw(after: 'echo "flags: env=$ENV_CHANGED mercure=$MERCURE_CHANGED"');
        self::assertSame('flags: env=1 mercure=1', $flags($out), 'a fresh install');

        [, $out] = $this->runStepRaw(after: 'echo "flags: env=$ENV_CHANGED mercure=$MERCURE_CHANGED"');
        self::assertSame('flags: env=0 mercure=0', $flags($out), 'a re-run that changes nothing must restart nothing');

        file_put_contents($dir.'/fleet.env', (string) preg_replace('/^FLEET_ADMIN_GROUP=.*\n/m', '', (string) file_get_contents($dir.'/fleet.env')));
        [, $out] = $this->runStepRaw(after: 'echo "flags: env=$ENV_CHANGED mercure=$MERCURE_CHANGED"');
        self::assertSame('flags: env=1 mercure=0', $flags($out), 'a key added by the upgrade: php-fpm and the workers must re-read their environment');
        self::assertArrayHasKey('FLEET_ADMIN_GROUP', $this->parse($dir.'/fleet.env'));

        file_put_contents($dir.'/fleet.env', (string) preg_replace('/^MERCURE_JWT_SECRET=.*$/m', 'MERCURE_JWT_SECRET='.str_repeat('e', 64), (string) file_get_contents($dir.'/fleet.env')));
        [, $out] = $this->runStepRaw(after: 'echo "flags: env=$ENV_CHANGED mercure=$MERCURE_CHANGED"');
        self::assertSame('flags: env=0 mercure=1', $flags($out), 'a new hub key restarts the hub');
    }

    public function testSecretsNeverReachTheOutput(): void
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        [$status, $out, $err] = $this->runStepRaw();
        self::assertSame(0, $status, $err);

        foreach ($this->parse($dir.'/fleet.env') as $key => $value) {
            if (str_ends_with($key, 'SECRET')) {
                self::assertStringNotContainsString($value, $out.$err, $key);
            }
        }
        self::assertStringContainsString('added APP_ENV APP_DEBUG APP_SECRET', $out, 'only the key names are logged');
    }

    public function testDryRunPrintsNoSecretAndWritesNothing(): void
    {
        [, $out] = $this->dryRun();

        self::assertStringContainsString('+ env /etc/ddev-fleet/fleet.env: APP_SECRET=<64 hex, generated once> (only when missing)', $out);
        self::assertStringContainsString('+ env /etc/ddev-fleet/fleet.env: FLEET_TRUSTED_PROXIES= (only when missing)', $out);
        self::assertStringContainsString('+ write /etc/ddev-fleet/fleet.env 0640 root:fleet', $out);
        self::assertStringContainsString('+ write /etc/ddev-fleet/mercure.env 0600 root:root', $out);
    }

    public function testADomainChangeIsReportedNotSilentlyApplied(): void
    {
        $dir = $this->runStep();

        [$status, , $err] = $this->runStepRaw(domain: 'other.example.org');

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('DEFAULT_URI', $err);
        self::assertSame('https://fleet.example.com', $this->parse($dir.'/fleet.env')['DEFAULT_URI'], 'only missing keys are added');
    }

    /** @return string the scratch "/etc/ddev-fleet" */
    private function runStep(?string $stubs = null): string
    {
        [$status, , $err] = $this->runStepRaw(stubs: $stubs);
        self::assertSame(0, $status, $err);

        return $this->scratch().'/etc-ddev-fleet';
    }

    /** @return array{int, string, string} */
    private function runStepRaw(?string $stubs = null, string $domain = 'fleet.example.com', string $after = ''): array
    {
        $dir = $this->scratch().'/etc-ddev-fleet';
        $stubs ??= $this->stubs(['chown' => 'exit 0']);
        $code = \sprintf(
            '. "$INSTALL_DIR/steps/70-env.sh"; FLEET_ETC_DIR=%s; DOMAIN=%s; FLEET_DRY_RUN=0; step_main; %s',
            escapeshellarg($dir),
            escapeshellarg($domain),
            '' === $after ? 'true' : $after,
        );

        return $this->withCommon($code, [], $stubs);
    }

    /** @return array<string, string> */
    private function parse(string $file): array
    {
        $env = [];
        foreach (explode("\n", rtrim((string) file_get_contents($file))) as $line) {
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[$key] = $value;
        }

        return $env;
    }
}
