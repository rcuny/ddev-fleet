<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SetupTokenCommand;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupTokenCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private SetupFixture $fx;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function run_(): CommandTester
    {
        $tester = new CommandTester(new SetupTokenCommand($this->fx->settings, $this->fx->tokens));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testPrintsExactlyOneUrlLine(): void
    {
        $this->fx->host();

        $tester = $this->run_();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $lines = explode("\n", rtrim($tester->getDisplay(), "\n"));
        self::assertCount(1, $lines);
        self::assertSame(1, preg_match('#^https://fleet\.example\.com/setup\?token=([A-Za-z0-9_-]{43})$#D', $lines[0], $m), $lines[0]);
        self::assertArrayHasKey(1, $m);
        self::assertTrue($this->fx->tokens->verify($m[1] ?? ''));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testAnotherRunReplacesTheToken(): void
    {
        $this->fx->host();
        self::assertSame(1, preg_match('/token=(\S+)/', $this->run_()->getDisplay(), $first));
        self::assertSame(1, preg_match('/token=(\S+)/', $this->run_()->getDisplay(), $second));
        self::assertArrayHasKey(1, $first);
        self::assertArrayHasKey(1, $second);

        self::assertFalse($this->fx->tokens->verify($first[1] ?? ''));
        self::assertTrue($this->fx->tokens->verify($second[1] ?? ''));
    }

    public function testRefusesWhenComplete(): void
    {
        $host = $this->fx->host();
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay(), 'nothing on stdout');
        self::assertStringContainsString('fleet:setup:reset', $tester->getErrorOutput());
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        self::assertNull($host->setupTokenHash());
    }

    public function testRefusesBeforeInit(): void
    {
        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('fleet:host:init', $tester->getErrorOutput());
    }
}
