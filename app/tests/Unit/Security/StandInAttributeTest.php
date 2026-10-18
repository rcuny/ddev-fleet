<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\StandIn\StandInAuthenticator;
use App\Security\StandIn\StandInController;
use App\Security\StandIn\StandInSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The stand-in must not be registered in prod: pins the attributes that keep it out of the container.
 */
final class StandInAttributeTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function standInClasses(): iterable
    {
        yield 'authenticator' => [StandInAuthenticator::class];
        yield 'controller' => [StandInController::class];
        yield 'settings' => [StandInSettings::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('standInClasses')]
    public function testOnlyDevAndTestEnvironmentsRegisterIt(string $class): void
    {
        $attributes = new \ReflectionClass($class)->getAttributes(When::class);

        $envs = array_map(static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->env, $attributes);
        sort($envs);

        self::assertSame(['dev', 'test'], $envs);
    }
}
