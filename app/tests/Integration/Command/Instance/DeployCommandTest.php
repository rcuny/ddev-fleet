<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\DiskHeadroom;
use App\Health\HostHealth;
use App\Tests\Support\InstanceCliTestCase;

final class DeployCommandTest extends InstanceCliTestCase
{
    public function testDeploysAttachedAndPrintsTheUrl(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 succeeded, 0 failed', $tester->getDisplay());
        self::assertStringContainsString('https://acme--develop.fleet.example.com', $tester->getDisplay());
        self::assertNotEmpty($this->runner()->callsMatching(['git']));
    }

    public function testDetachOnlyQueues(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--detach' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^job [0-9]+ queued$/m', $tester->getDisplay());
        self::assertStringNotContainsString('succeeded', $tester->getDisplay());
        self::assertStringNotContainsString('https://', $tester->getDisplay());
    }

    public function testCountTwoCreatesSuffixedInstances(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '2']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['acme--develop-1', 'acme--develop-2'], $this->ids());
        self::assertStringContainsString('2 succeeded, 0 failed', $tester->getDisplay());
    }

    public function testCountZeroDeploysNothing(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('nothing to deploy', $tester->getDisplay());
        self::assertSame([], $this->ids());
    }

    public function testCountAboveTwentyExitsOne(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '21']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--count: ', $tester->getErrorOutput());
        self::assertSame([], $this->ids());
    }

    public function testUnknownProjectExitsThree(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'nope']);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Project "nope" does not exist.', $tester->getErrorOutput());
    }

    public function testJsonErrorShape(): void
    {
        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '99', '--json' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $document = $this->decode($tester);
        self::assertSame('Invalid values.', $document['error']);
        self::assertIsArray($document['fields']);
        self::assertArrayHasKey('--count', $document['fields']);
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testDiskRefusalExitsTwo(): void
    {
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::getContainer()->set(DiskHeadroom::class, new DiskHeadroom(new HostHealth($paths), 1.01));

        $tester = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '2']);

        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('of the disk is free', $tester->getErrorOutput());
        self::assertSame([], $this->ids());

        $skipped = $this->command('fleet:instance:deploy', ['project' => 'acme', '--count' => '2', '--skip-disk-check' => true]);
        self::assertSame(0, $skipped->getStatusCode(), $skipped->getDisplay());
    }

    public function testJobShowsInTheUiWithTriggerCli(): void
    {
        $this->command('fleet:instance:deploy', ['project' => 'acme']);

        $this->em->clear();
        $jobs = self::getContainer()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);
        $found = $jobs->search([], 1)['rows'];
        self::assertCount(1, $found);
        self::assertSame(JobTrigger::Cli, $found[0]->trigger());
        self::assertMatchesRegularExpression('/^cli:[A-Za-z0-9._-]+$/', $found[0]->requestedBy());
        self::assertSame('acme--develop', $found[0]->instanceId());
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $this->em->clear();
        $ids = new InstanceRepository($this->em)->ids();
        sort($ids);

        return $ids;
    }
}
