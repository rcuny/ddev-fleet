<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HostConcurrencyCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private HostSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->settings = new HostSettingsRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testFailsBeforeTheHostIsInitialised(): void
    {
        self::assertSame(1, $this->run_([])->getStatusCode());
    }

    public function testShowsDefaultsAndSetsTheLimit(): void
    {
        $this->settings->getOrCreate('fleet.example.com', new \DateTimeImmutable());

        $show = $this->run_([]);
        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('Jobs at the same time: 1', $show->getDisplay());

        $set = $this->run_(['jobs' => '4']);
        self::assertSame(0, $set->getStatusCode());
        self::assertStringContainsString('Jobs at the same time: 4', $set->getDisplay());
        self::assertSame(4, $this->settings->fresh()?->maxConcurrentJobs());
    }

    public function testRejectsOutOfRangeValues(): void
    {
        $this->settings->getOrCreate('fleet.example.com', new \DateTimeImmutable());

        foreach (['0', '33', 'x', '-2', '1.5'] as $bad) {
            self::assertSame(2, $this->run_(['jobs' => $bad])->getStatusCode(), $bad);
        }
        self::assertSame(1, $this->settings->fresh()?->maxConcurrentJobs());
    }

    /**
     * @param array<string, string> $arguments
     */
    private function run_(array $arguments): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('fleet:host:job-concurrency'));
        $tester->execute($arguments);

        return $tester;
    }
}
