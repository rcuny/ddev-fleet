<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\FleetPaths;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * For KernelTestCase subclasses: a fresh FLEET_HOME whose fleet database was built by the real migrations
 * (never by schema:create), so a test sees exactly the schema production gets.
 *
 * Call setUpFleetDatabase() from the test and tearDownFleetDatabase() from tearDown().
 */
trait FleetDatabase
{
    use TempFleetHome;

    private function setUpFleetDatabase(): EntityManagerInterface
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        self::getContainer()->get(FleetPaths::class)->ensureLayout();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true]);

        return $this->entityManager();
    }

    private function tearDownFleetDatabase(): void
    {
        self::ensureKernelShutdown();
        $this->restoreFleetHome();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * Runs a console command in the booted kernel and returns [exit code, output]. phpunit.dist.xml sets
     * SHELL_VERBOSITY=-1, so --verbose is passed to get any output at all.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{int, string}
     */
    private function console(string $command, array $arguments = []): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $exit = $application->run(new ArrayInput(['command' => $command, '--verbose' => true] + $arguments), $output);
        $text = $output->fetch();
        if (0 !== $exit && 'doctrine:migrations:migrate' === $command) {
            self::fail('Migrations failed: '.$text);
        }

        return [$exit, $text];
    }
}
