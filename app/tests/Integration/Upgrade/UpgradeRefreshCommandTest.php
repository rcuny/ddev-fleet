<?php

declare(strict_types=1);

namespace App\Tests\Integration\Upgrade;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\ReleaseCatalog;
use App\Message\RefreshReleaseCatalog;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class UpgradeRefreshCommandTest extends KernelTestCase
{
    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.command_runner.recording');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);
        $runner->reset();

        return $runner;
    }

    private function refresh(string ...$options): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('fleet:upgrade:refresh'));
        $tester->execute(array_fill_keys($options, true), ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testRefreshIsSilentOnSuccessAndFillsTheCatalog(): void
    {
        self::bootKernel();
        $this->runner()->expect(['git', 'ls-remote'], new CommandResult(0, str_repeat('a', 40)."\trefs/tags/v1.2.0^{}\n"));
        self::getContainer()->get('cache.app')->deleteItem(ReleaseCatalog::CACHE_KEY);

        $tester = $this->refresh();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame('v1.2.0', self::getContainer()->get(ReleaseCatalog::class)->state()->latest()?->tag());
    }

    public function testAnUnreachableSourceWarnsOnStderrButExitsZeroAndKeepsTheList(): void
    {
        self::bootKernel();
        $runner = $this->runner();
        $runner->expect(['git', 'ls-remote'], new CommandResult(0, str_repeat('a', 40)."\trefs/tags/v1.2.0^{}\n"));
        $this->refresh();
        $runner->expect(['git', 'ls-remote'], new CommandResult(128, '', 'fatal: could not resolve host'));

        $tester = $this->refresh();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not resolve host', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
        $state = self::getContainer()->get(ReleaseCatalog::class)->state();
        self::assertSame('v1.2.0', $state->latest()?->tag());
        self::assertNotNull($state->error);
    }

    public function testIfStaleSkipsTheNetworkWhileTheCatalogIsFresh(): void
    {
        self::bootKernel();
        $runner = $this->runner();
        $runner->expect(['git', 'ls-remote'], new CommandResult(0, ''));
        $this->refresh();
        $before = \count($runner->callsMatching(['git', 'ls-remote']));

        $this->refresh('--if-stale');

        self::assertSame($before, \count($runner->callsMatching(['git', 'ls-remote'])));
    }

    public function testTheMessageHandlerRefreshesTheCatalog(): void
    {
        self::bootKernel();
        $runner = $this->runner();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RefreshReleaseCatalog());

        self::assertCount(1, $runner->callsMatching(['git', 'ls-remote']));
    }
}
