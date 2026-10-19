<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The committed .env is what a deployment gets when it forgets an override. It must be fail-closed:
 * no secret, the real runner, a stand-in that signs nobody in.
 */
final class CommittedEnvDefaultsTest extends TestCase
{
    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->env = new Dotenv()->parse((string) file_get_contents(\dirname(__DIR__, 2).'/.env'));
    }

    public function testNoSecretIsCommitted(): void
    {
        self::assertSame('', $this->env['APP_SECRET'] ?? null);
        self::assertSame('', $this->env['MERCURE_JWT_SECRET'] ?? null);
    }

    public function testTheRealRunnerIsTheDefault(): void
    {
        self::assertSame('process', $this->env['FLEET_RUNNER'] ?? null);
    }

    public function testTheStandInDefaultRoleIsNone(): void
    {
        self::assertSame('none', $this->env['FLEET_STAND_IN_ROLE'] ?? null);
    }

    public function testTheProxySecretIsEmptyAndCaddyDirLivesUnderFleetHome(): void
    {
        self::assertArrayHasKey('FLEET_PROXY_SECRET', $this->env);
        self::assertSame('', $this->env['FLEET_PROXY_SECRET']);
        self::assertSame('%env(resolve:FLEET_HOME)%/caddy', $this->env['FLEET_CADDY_DIR'] ?? null);
    }

    public function testOnlyTheDevAndTestFilesOptIntoAdmin(): void
    {
        $dev = new Dotenv()->parse((string) file_get_contents(\dirname(__DIR__, 2).'/.env.dev'));

        self::assertSame('admin', $dev['FLEET_STAND_IN_ROLE'] ?? null);
        self::assertFileDoesNotExist(\dirname(__DIR__, 2).'/.env.prod');
    }
}
