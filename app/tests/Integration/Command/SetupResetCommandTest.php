<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SetupResetCommand;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class SetupResetCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private SetupFixture $fx;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $inputs
     */
    private function reset(array $options = [], bool $interactive = false, array $inputs = []): CommandTester
    {
        $tester = new CommandTester(new SetupResetCommand($this->fx->settings, $this->fx->tokens, $this->fx->system, $this->logger, $this->fx->clock, false));
        $tester->setInputs($inputs);
        $tester->execute($options, ['capture_stderr_separately' => true, 'interactive' => $interactive]);

        return $tester;
    }

    private function completedHost(): void
    {
        $host = $this->fx->readyHost();
        $oldToken = $this->fx->tokens->mint();
        $this->fx->finisher->finish('alice');
        $this->fx->events = [];
        self::assertFalse($this->fx->tokens->verify($oldToken));
        self::assertStringContainsString('forward_auth', (string) file_get_contents($this->fx->paths->gateFile()));
        self::assertTrue($host->isSetupComplete());
    }

    public function testReopensMintsAndRendersSetupGate(): void
    {
        $this->completedHost();

        $tester = $this->reset(['--yes' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame(1, preg_match('#^https://fleet\.example\.com/setup\?token=([A-Za-z0-9_-]{43})$#D', rtrim($tester->getDisplay(), "\n"), $m));
        self::assertArrayHasKey(1, $m);
        self::assertTrue($this->fx->tokens->verify($m[1] ?? ''));
        self::assertFalse($this->fx->settings->get()?->isSetupComplete());
        self::assertStringNotContainsString('forward_auth', (string) file_get_contents($this->fx->paths->gateFile()));
        self::assertSame(['caddy:validate', 'caddy:reload'], $this->fx->events);
        self::assertCount(1, $this->fx->users->all(), 'users are kept');
    }

    public function testAskForConfirmationOnATerminal(): void
    {
        $this->completedHost();

        $declined = $this->reset([], true, ['no']);
        self::assertSame(1, $declined->getStatusCode());
        self::assertTrue($this->fx->settings->get()?->isSetupComplete());
        self::assertSame([], $this->fx->events);

        $confirmed = $this->reset([], true, ['yes']);
        self::assertSame(0, $confirmed->getStatusCode(), $confirmed->getErrorOutput());
        self::assertFalse($this->fx->settings->get()?->isSetupComplete());
    }

    public function testWithoutATerminalItNeedsYes(): void
    {
        $this->completedHost();

        $tester = $this->reset();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--yes', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
        self::assertTrue($this->fx->settings->get()?->isSetupComplete());
    }

    public function testAFailedGateUndoesTheReset(): void
    {
        $this->completedHost();
        $this->fx->caddyFails = ['validate'];

        $tester = $this->reset(['--yes' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay(), 'no URL for a wizard that cannot be reached');
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        self::assertTrue($host->isSetupComplete());
        self::assertNull($host->setupTokenHash());
        self::assertStringContainsString('forward_auth', (string) file_get_contents($this->fx->paths->gateFile()));
    }

    public function testAnOldCookieCannotUseTheReopenedWizard(): void
    {
        $host = $this->fx->readyHost();
        $this->fx->tokens->mint();
        $cookie = new \App\Setup\SetupCookie('a-long-enough-app-secret-for-tests', $this->fx->clock);
        $issued = $cookie->issue($host)->getValue();
        $this->fx->finisher->finish('alice');

        $this->reset(['--yes' => true]);

        self::assertFalse($cookie->isValid($issued, $this->fx->settings->get() ?? $host), 'a session from before completion stays dead');
    }

    public function testTheConfirmationPromptGoesToStderrNotStdout(): void
    {
        $this->completedHost();

        $tester = $this->reset([], true, ['yes']);

        self::assertStringContainsString('Reopen the setup wizard?', $tester->getErrorOutput());
        self::assertStringNotContainsString('Reopen', $tester->getDisplay());
        self::assertSame(1, preg_match('#^https://fleet\.example\.com/setup\?token=\S+$#D', rtrim($tester->getDisplay(), "\n")), 'stdout is the URL and nothing else');
    }

    public function testReopeningIsAuditedWithoutTheToken(): void
    {
        $this->completedHost();

        $tester = $this->reset(['--yes' => true]);

        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        [$level, $message, $context] = $logs[0];
        self::assertSame('warning', $level);
        self::assertSame('Setup reopened from the host shell.', $message);
        self::assertSame('2026-10-19T10:00:00+00:00', $context['at']);
        self::assertNotSame('', $context['user']);
        $token = (string) substr(strrchr(trim($tester->getDisplay()), '=') ?: '', 1);
        self::assertNotSame('', $token);
        self::assertStringNotContainsString($token, (string) json_encode($logs));
    }

    public function testNothingIsAuditedWhenTheResetFails(): void
    {
        $this->completedHost();
        $this->fx->caddyFails = ['validate'];

        $this->reset(['--yes' => true]);

        self::assertSame([], $this->logger->cleanLogs());
    }
}
