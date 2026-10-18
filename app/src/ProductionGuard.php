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
     * @param array<string, mixed> $vars the variables the guard needs (APP_SECRET, FLEET_RUNNER), missing = absent
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
    }

    /**
     * Reads the variables the way the Symfony env processor does: $_ENV, then $_SERVER, then the process environment.
     *
     * @return array<string, mixed>
     */
    public static function currentVars(): array
    {
        $vars = [];
        foreach (['APP_SECRET', 'FLEET_RUNNER'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            $vars[$name] = false === $value ? null : $value;
        }

        return $vars;
    }
}
