<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * What the installer generates must boot the production kernel: step 70 writes fleet.env into a scratch directory, a
 * subprocess starts from an EMPTY environment, loads that file the way the unit and the `fleet` wrapper do (set -a)
 * and runs the console. Only the paths that cannot exist off a server (FLEET_HOME, FLEET_CADDY_DIR, the cache) are
 * pointed at the scratch directory afterwards.
 */
final class InstalledEnvBootsProdTest extends TestCase
{
    use InstallerHarness;

    private string $envFile = '';
    private string $launcher = '';

    protected function setUp(): void
    {
        $etc = $this->scratch().'/etc-ddev-fleet';
        $stubs = $this->stubs(['chown' => 'exit 0']);
        [$status, , $err] = $this->withCommon(
            '. "$INSTALL_DIR/steps/70-env.sh"; FLEET_ETC_DIR='.escapeshellarg($etc).'; DOMAIN=fleet.example.com; step_main',
            [],
            $stubs,
        );
        self::assertSame(0, $status, $err);
        $this->envFile = $etc.'/fleet.env';

        mkdir($this->scratch().'/home');
        mkdir($this->scratch().'/cache');
        // Same entry point as bin/console, with the cache and log directories in the scratch directory (the real
        // var/cache/prod of a developer checkout is not touched).
        $app = \dirname(__DIR__, 2);
        $this->launcher = $this->scratch().'/console.php';
        file_put_contents($this->launcher, <<<PHP
            <?php
            use App\\Kernel;
            use Symfony\\Bundle\\FrameworkBundle\\Console\\Application;

            require_once '{$app}/vendor/autoload_runtime.php';

            return function (array \$context) {
                \$kernel = new class(\$context['APP_ENV'], (bool) \$context['APP_DEBUG']) extends Kernel {
                    public function getProjectDir(): string { return '{$app}'; }
                    public function getCacheDir(): string { return \$_SERVER['TEST_CACHE_DIR']; }
                    public function getLogDir(): string { return \$_SERVER['TEST_CACHE_DIR'].'/log'; }
                };

                return new Application(\$kernel);
            };
            PHP);
    }

    public function testFreshInstallEnvBootsProd(): void
    {
        [$status, $out, $err] = $this->console(['about']);

        self::assertSame(0, $status, $err.$out);
        self::assertMatchesRegularExpression('/Environment\s+prod\b/', $out);
        self::assertMatchesRegularExpression('/Debug\s+(false|disabled)\b/', $out);
    }

    public function testTheGeneratedEnvSatisfiesTheProductionGuardWithTheDotenvDefaultsStillPresent(): void
    {
        // The committed .env says FLEET_TRUSTED_PROXIES=127.0.0.1,::1 and an empty APP_SECRET/FLEET_PROXY_SECRET; the
        // real environment must win, an explicitly EMPTY FLEET_TRUSTED_PROXIES included, or the guard refuses to boot.
        [$status, $out, $err] = $this->console(['about']);
        self::assertSame(0, $status, $err.$out);
        self::assertStringNotContainsString('Refusing to boot', $out.$err);

        // And the guard really is armed: the same environment with the dotenv default for the proxy list is refused.
        [$status, $out, $err] = $this->console(['about'], 'FLEET_TRUSTED_PROXIES=127.0.0.1,::1');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('Refusing to boot in prod', $out.$err);
    }

    public function testStandInRoutesAreAbsent(): void
    {
        [$status, $out, $err] = $this->console(['debug:router', '--format=json']);

        self::assertSame(0, $status, $err.$out);
        $routes = json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($routes);
        self::assertNotSame([], $routes);
        foreach ($routes as $name => $route) {
            self::assertIsArray($route);
            self::assertStringNotContainsString('stand-in', (string) $name.json_encode($route));
            self::assertStringNotContainsString('/_dev', (string) ($route['path'] ?? ''));
        }
    }

    public function testPoolPinsAppEnvProd(): void
    {
        $pool = (string) file_get_contents(self::installDir().'/etc/php-fpm-fleet.conf');

        self::assertMatchesRegularExpression('/^env\[APP_ENV\] = prod$/m', $pool);
        self::assertMatchesRegularExpression('/^clear_env = no$/m', $pool);
        self::assertMatchesRegularExpression('/^env\[HOME\] = \/home\/fleet$/m', $pool, 'the root master HOME must not reach the requests');
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function console(array $args, ?string $override = null): array
    {
        $cmd = \sprintf(
            'set -a; . %s; set +a; FLEET_HOME=%s; FLEET_CADDY_DIR=%s; %s exec php8.4 %s %s',
            escapeshellarg($this->envFile),
            escapeshellarg($this->scratch().'/home'),
            escapeshellarg($this->scratch().'/caddy'),
            null === $override ? '' : 'export '.$override.';',
            escapeshellarg($this->launcher),
            implode(' ', array_map('escapeshellarg', $args)),
        );
        $process = new Process(['env', '-i', 'PATH='.getenv('PATH'), 'HOME='.$this->scratch(), 'TEST_CACHE_DIR='.$this->scratch().'/cache', 'APP_RUNTIME_OPTIONS={"project_dir":"'.\dirname(__DIR__, 2).'"}', 'bash', '-c', $cmd], \dirname(__DIR__, 2), null, null, 120);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }
}
