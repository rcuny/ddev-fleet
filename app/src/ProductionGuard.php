<?php

declare(strict_types=1);

namespace App;

/**
 * Refuses to start a production kernel that carries development-only configuration.
 *
 * The committed .env defaults are dev-safe on purpose (empty APP_SECRET, FLEET_RUNNER chosen per environment);
 * a deployment that forgets to override them must fail loudly at boot, not run unsafely.
 */
final class ProductionGuard
{
    /**
     * @param array<string, mixed> $vars the variables the guard needs (APP_SECRET, FLEET_RUNNER, FLEET_HOME, PROJECT_DIR,
     *                                   FLEET_PROXY_SECRET, FLEET_TRUSTED_PROXIES), missing = absent
     *
     * @throws \LogicException when the environment is prod and the configuration is unsafe
     */
    public static function assertSafe(string $environment, array $vars): void
    {
        if ('prod' !== $environment) {
            return;
        }

        $secret = $vars['APP_SECRET'] ?? null;
        if (!\is_string($secret) || '' === trim($secret)) {
            throw new \LogicException('Refusing to boot in prod: APP_SECRET is empty. Set a long random secret in the real environment (systemd EnvironmentFile or composer dump-env).');
        }

        $runner = $vars['FLEET_RUNNER'] ?? null;
        if (\is_string($runner) && 'recording' === strtolower(trim($runner))) {
            throw new \LogicException('Refusing to boot in prod: FLEET_RUNNER=recording would make every host command a silent no-op. Use FLEET_RUNNER=process.');
        }

        self::assertHomeIsOutsideTheProject($vars);
        self::assertProxyTrustIsUnambiguous($vars);
    }

    /**
     * The dotenv default FLEET_HOME lives inside the project (var/fleet-home): fine for development, wrong for a
     * deployment, where it would put the database, keys and SSH identities into the code tree (and lose them on the
     * next release). A real FLEET_HOME must be set in the environment.
     *
     * @param array<string, mixed> $vars
     */
    private static function assertHomeIsOutsideTheProject(array $vars): void
    {
        $home = $vars['FLEET_HOME'] ?? null;
        if (!\is_string($home) || '' === trim($home)) {
            return;
        }
        $home = trim($home);
        $project = $vars['PROJECT_DIR'] ?? null;

        $inside = str_contains($home, '%kernel.project_dir%')
            || (\is_string($project) && '' !== rtrim($project, '/') && str_starts_with(rtrim($home, '/').'/', rtrim($project, '/').'/'));
        if ($inside) {
            throw new \LogicException('Refusing to boot in prod: FLEET_HOME points inside the application directory (the development default). Set FLEET_HOME to the fleet state directory (e.g. /srv/fleet) in the real environment.');
        }
    }

    /**
     * With a proxy secret configured the proxy is identified by the secret, so a non-empty peer list is both
     * redundant and dangerous (the committed .env default is 127.0.0.1,::1, which behind php_fastcgi would be wrong
     * to trust); a secret under 32 characters is silently ignored by the checker, so say so.
     *
     * @param array<string, mixed> $vars
     */
    private static function assertProxyTrustIsUnambiguous(array $vars): void
    {
        $secret = $vars['FLEET_PROXY_SECRET'] ?? null;
        if (!\is_string($secret) || '' === $secret) {
            return;
        }

        $proxies = $vars['FLEET_TRUSTED_PROXIES'] ?? null;
        if (\is_string($proxies) && '' !== trim($proxies, " \t,")) {
            throw new \LogicException('Refusing to boot in prod: FLEET_PROXY_SECRET is set, so FLEET_TRUSTED_PROXIES must be empty (the proxy is identified by the secret, not by its address).');
        }

        if (\strlen($secret) < 32) {
            error_log('ddev-fleet: FLEET_PROXY_SECRET is shorter than 32 characters and is ignored; nobody can sign in through the proxy until it is regenerated.');
        }
    }

    /**
     * Reads the variables the way the Symfony env processor does: $_ENV, then $_SERVER, then the process environment.
     *
     * @return array<string, mixed>
     */
    public static function currentVars(): array
    {
        $vars = [];
        foreach (['APP_SECRET', 'FLEET_RUNNER', 'FLEET_HOME', 'FLEET_PROXY_SECRET', 'FLEET_TRUSTED_PROXIES'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            $vars[$name] = false === $value ? null : $value;
        }

        return $vars;
    }
}
