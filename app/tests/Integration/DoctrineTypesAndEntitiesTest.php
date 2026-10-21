<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineTypesAndEntitiesTest extends KernelTestCase
{
    /** The stock types that read in PHP's default zone; the UTC type is the only one entities may use. */
    private const array STOCK_DATE_TYPES = [
        'date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable', 'time', 'time_immutable',
    ];

    public function testNoEntityFieldUsesAStockDateTimeType(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $checked = 0;
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $name => $mapping) {
                self::assertNotContains($mapping->type, self::STOCK_DATE_TYPES, \sprintf('%s::$%s must use "%s".', $metadata->getName(), $name, UtcDateTimeImmutableType::NAME));
                ++$checked;
            }
        }
        self::assertGreaterThan(50, $checked);
    }

    public function testEntitiesAndTheTypeAreNotRegisteredAsServices(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $classes = array_map(static fn ($m) => $m->getName(), $em->getMetadataFactory()->getAllMetadata());
        $classes[] = UtcDateTimeImmutableType::class;
        self::assertGreaterThan(10, \count($classes));

        $registered = array_filter($classes, static fn (string $class): bool => self::registeredByServicesYaml($class));
        self::assertSame([], array_values($registered), 'config/services.yaml must exclude these from App\\ registration.');
    }

    private static function registeredByServicesYaml(string $class): bool
    {
        // The compiled container drops unused private services, so ask the source: services.yaml must exclude the file.
        $yaml = (string) file_get_contents(\dirname(__DIR__, 2).'/config/services.yaml');
        $path = '../src/'.str_replace('\\', '/', substr($class, 4)).'.php';

        return !str_contains($yaml, "'".$path."'");
    }
}
