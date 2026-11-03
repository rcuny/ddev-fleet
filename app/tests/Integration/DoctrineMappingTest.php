<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineMappingTest extends KernelTestCase
{
    public function testEntitiesUnderAppDomainAreMappedByAttributes(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $wrapper = $em->getConfiguration()->getMetadataDriverImpl();
        self::assertInstanceOf(MappingDriver::class, $wrapper);
        $chain = $wrapper->getDriver();
        self::assertInstanceOf(MappingDriverChain::class, $chain);

        $drivers = $chain->getDrivers();
        self::assertArrayHasKey('App\Domain', $drivers);
        $driver = $drivers['App\Domain'];
        self::assertInstanceOf(AttributeDriver::class, $driver);
        self::assertSame([\dirname(__DIR__, 2).'/src/Domain'], $driver->getPaths());
    }

    public function testAnEntityPlacedUnderAppDomainGetsMetadata(): void
    {
        $dir = \dirname(__DIR__, 2).'/src/Domain/MappingProbe';
        $file = $dir.'/ProbeEntity.php';
        mkdir($dir, 0o755, true);

        try {
            file_put_contents($file, <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Domain\MappingProbe;

                use Doctrine\ORM\Mapping as ORM;

                #[ORM\Entity]
                #[ORM\Table(name: 'mapping_probe')]
                class ProbeEntity
                {
                    #[ORM\Id]
                    #[ORM\Column(type: 'integer')]
                    public int $id = 0;
                }
                PHP);
            require_once $file;

            self::bootKernel();
            $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            $tables = [];
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
                $tables[$metadata->getName()] = $metadata->getTableName();
            }
            self::assertSame('mapping_probe', $tables['App\Domain\MappingProbe\ProbeEntity'] ?? null);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testOnlyTheDefaultConnectionHasAnEntityManager(): void
    {
        self::bootKernel();

        self::assertSame(
            ['default' => 'doctrine.orm.default_entity_manager'],
            self::getContainer()->getParameter('doctrine.entity_managers'),
        );
    }
}
