<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Domain\Host\TlsMode;
use App\Fleet\System\PrivilegedApplyFailed;
use App\Setup\SetupFinisher;
use App\Setup\SetupRefused;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SetupFinisherTest extends KernelTestCase
{
    use FleetDatabase;

    private SetupFixture $fx;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(\App\Fleet\FleetPaths::class);
        self::assertInstanceOf(\App\Fleet\FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function finishFailing(): \Throwable
    {
        try {
            $this->fx->finisher->finish('alice');
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected finish to fail.');
    }

    private function gate(): string
    {
        return (string) @file_get_contents($this->fx->paths->gateFile());
    }

    public function testSideEffectsRunInTheDocumentedOrder(): void
    {
        $host = $this->fx->readyHost();
        $token = $this->fx->tokens->mint();

        // What is true at each side effect: the users file exists before Authelia is told, the gate is already the
        // Authelia gate when Caddy is reloaded, and nothing is complete until the very end.
        $facts = [];
        $this->fx->probe = function (string $event) use (&$facts, $host): void {
            $facts[] = [
                $event,
                is_file($this->fx->paths->usersFile()),
                str_contains($this->gate(), 'forward_auth'),
                $host->isSetupComplete(),
            ];
        };

        $result = $this->fx->finisher->finish('alice');

        self::assertSame(['apply:authelia', 'caddy:validate', 'caddy:reload'], $this->fx->events);
        self::assertSame([
            ['apply:authelia', true, false, false],
            ['caddy:validate', true, true, false],
            ['caddy:reload', true, true, false],
        ], $facts);
        self::assertSame(['users', 'authelia', 'gate', 'complete'], $result->steps);
        self::assertTrue($host->isSetupComplete());
        self::assertFalse($this->fx->tokens->verify($token));
        self::assertSame('https://fleet.example.com/', $result->url());
        self::assertSame([], $this->fx->staged());
    }

    public function testOvhModeAppliesCaddyAfterTheGateAndBeforeCompletion(): void
    {
        $host = $this->fx->readyHost(TlsMode::OvhDns);
        $this->fx->tokens->mint();
        $completeAtCaddy = null;
        $this->fx->probe = function (string $event) use (&$completeAtCaddy, $host): void {
            if ('apply:caddy' === $event) {
                $completeAtCaddy = $host->isSetupComplete();
                // OVH credentials are staged for exactly this call
                self::assertSame(['caddy/Caddyfile', 'caddy/env'], $this->fx->staged());
                self::assertStringContainsString('OVH_APPLICATION_SECRET=', (string) file_get_contents($this->fx->paths->stagingFile('caddy', 'env')));
            }
        };

        $result = $this->fx->finisher->finish('alice');

        self::assertSame(['apply:authelia', 'caddy:validate', 'caddy:reload', 'apply:caddy'], $this->fx->events);
        self::assertFalse($completeAtCaddy);
        self::assertSame(['users', 'authelia', 'gate', 'caddy', 'complete'], $result->steps);
        self::assertSame([], $this->fx->staged(), 'no OVH credential file is left behind');
    }

    public function testHttp01SkipsCaddyApply(): void
    {
        $this->fx->readyHost();
        $this->fx->tokens->mint();

        $this->fx->finisher->finish('alice');

        self::assertNotContains('apply:caddy', $this->fx->events);
    }

    public function testApplyAutheliaFailureLeavesSetupOpenAndTokenValid(): void
    {
        $host = $this->fx->readyHost();
        $token = $this->fx->tokens->mint();
        $this->fx->applyFails['authelia'] = new PrivilegedApplyFailed('helper refused');

        $e = $this->finishFailing();
        self::assertInstanceOf(PrivilegedApplyFailed::class, $e);
        self::assertSame('helper refused', $e->getMessage());

        self::assertFalse($host->isSetupComplete());
        self::assertTrue($this->fx->tokens->verify($token));
        self::assertSame(['apply:authelia'], $this->fx->events, 'the gate is not flipped after a failed Authelia apply');
        self::assertStringNotContainsString('forward_auth', $this->gate());
        self::assertSame([], $this->fx->staged());
    }

    public function testApplyCaddyFailurePutsTheSetupGateBack(): void
    {
        $host = $this->fx->readyHost(TlsMode::OvhDns);
        $token = $this->fx->tokens->mint();
        $this->fx->applyFails['caddy'] = new PrivilegedApplyFailed('caddy refused');

        self::assertInstanceOf(PrivilegedApplyFailed::class, $this->finishFailing());

        self::assertFalse($host->isSetupComplete());
        self::assertTrue($this->fx->tokens->verify($token));
        self::assertStringNotContainsString('forward_auth', $this->gate(), 'the wizard stays reachable for the retry');
        self::assertSame([], $this->fx->staged());
    }

    public function testAGateReloadFailureLeavesSetupOpen(): void
    {
        $host = $this->fx->readyHost();
        $token = $this->fx->tokens->mint();
        $this->fx->caddyFails = ['reload'];

        try {
            $this->fx->finisher->finish('alice');
            self::fail('Expected the failure to surface.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Caddy reload failed', $e->getMessage());
        }

        self::assertFalse($host->isSetupComplete());
        self::assertTrue($this->fx->tokens->verify($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingPreconditions(): iterable
    {
        yield 'no admin' => ['admin'];
        yield 'no deploy key' => ['deploy'];
        yield 'ovh secrets missing' => ['ovh'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingPreconditions')]
    public function testMissingPreconditionsAreRefusedBeforeAnySideEffect(string $missing): void
    {
        $host = $this->fx->host('ovh' === $missing ? TlsMode::OvhDns : TlsMode::Http01);
        'admin' === $missing || $this->fx->addAdmin();
        if ('deploy' === $missing) {
            $this->fx->keys->save(new \App\Domain\Ssh\SshKey(\App\Domain\Ssh\SshKeyRole::Push, 'ssh-ed25519 AAAA', 'SHA256:x'));
        } else {
            $this->fx->addKeys();
        }
        $token = $this->fx->tokens->mint();

        try {
            $this->fx->finisher->finish('alice');
            self::fail('Expected a refusal.');
        } catch (SetupRefused) {
        }

        self::assertSame([], $this->fx->events);
        self::assertFileDoesNotExist($this->fx->paths->usersFile());
        self::assertFalse($host->isSetupComplete());
        self::assertTrue($this->fx->tokens->verify($token));
    }

    public function testMissingAdminIsRefused(): void
    {
        $this->fx->host();
        $this->fx->addKeys();

        $this->expectException(SetupRefused::class);
        $this->expectExceptionMessage('Create the administrator first.');
        $this->fx->finisher->finish('alice');
    }

    public function testAnUnsafeDomainIsRefusedBeforeAnySideEffect(): void
    {
        $host = $this->fx->readyHost();
        $host->changeDomain("evil.example.com\n}\nimport /etc/passwd");
        $this->fx->settings->save($host);

        try {
            $this->fx->finisher->finish('alice');
            self::fail('Expected a refusal.');
        } catch (SetupRefused $e) {
            self::assertStringContainsString('not a valid', $e->getMessage());
        }
        self::assertSame([], $this->fx->events);
        self::assertSame([], $this->fx->staged());
    }

    public function testCompletionIsMarkedLastAndInvalidatesToken(): void
    {
        $host = $this->fx->readyHost();
        $token = $this->fx->tokens->mint();
        $before = $this->fx->clock->now();
        $this->fx->clock->sleep(3);

        $result = $this->fx->finisher->finish('alice');

        self::assertEquals($before->modify('+3 seconds'), $host->setupCompletedAt());
        self::assertEquals($host->setupCompletedAt(), $result->completedAt);
        self::assertNull($host->setupTokenHash());
        self::assertFalse($this->fx->tokens->verify($token));
        // persisted, not just in memory
        $this->entityManager()->clear();
        $reloaded = $this->fx->settings->get();
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isSetupComplete());
        self::assertNull($reloaded->setupTokenHash());
    }

    public function testSecondFinishAfterFailureSucceeds(): void
    {
        $host = $this->fx->readyHost(TlsMode::OvhDns);
        $this->fx->tokens->mint();
        $this->fx->applyFails['authelia'] = new PrivilegedApplyFailed('helper refused');
        self::assertInstanceOf(PrivilegedApplyFailed::class, $this->finishFailing());

        $this->fx->applyFails = [];
        $result = $this->fx->finisher->finish('alice');

        self::assertTrue($host->isSetupComplete());
        self::assertSame(['users', 'authelia', 'gate', 'caddy', 'complete'], $result->steps);
    }

    public function testASecondFinishAfterSuccessIsRefused(): void
    {
        $this->fx->readyHost();
        $this->fx->tokens->mint();
        $this->fx->finisher->finish('alice');
        $this->fx->events = [];

        try {
            $this->fx->finisher->finish('alice');
            self::fail('Expected a refusal.');
        } catch (SetupRefused $e) {
            self::assertStringContainsString('already complete', $e->getMessage());
        }
        self::assertSame([], $this->fx->events);
    }

    public function testAConcurrentFinishIsRefused(): void
    {
        $this->fx->readyHost();
        $this->fx->tokens->mint();
        $store = new InMemoryStore();
        $finisher = $this->finisherWithLocks(new LockFactory($store));
        $other = new LockFactory($store)->createLock('setup-finish', 60, false);
        self::assertTrue($other->acquire());

        try {
            $finisher->finish('alice');
            self::fail('Expected a refusal.');
        } catch (SetupRefused $e) {
            self::assertStringContainsString('already running', $e->getMessage());
        }
        self::assertSame([], $this->fx->events);

        $other->release();
        $finisher->finish('alice');
        $reloaded = $this->fx->settings->get();
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isSetupComplete());
    }

    public function testAStepIsNotStartedWhenItsWorstCaseBreaksTheBudget(): void
    {
        $host = $this->fx->readyHost(TlsMode::OvhDns);
        $token = $this->fx->tokens->mint();
        // Authelia "took" 100 s: the gate (worst case 60 s) would end after the 150 s budget.
        $this->fx->probe = function (string $event): void {
            if ('apply:authelia' === $event) {
                $this->fx->clock->sleep(100);
            }
        };

        try {
            $this->fx->finisher->finish('alice');
            self::fail('Expected a refusal.');
        } catch (SetupRefused $e) {
            self::assertStringContainsString('ran out of time before "gate"', $e->getMessage());
        }

        self::assertSame(['apply:authelia'], $this->fx->events);
        self::assertFalse($host->isSetupComplete());
        self::assertTrue($this->fx->tokens->verify($token));
    }

    public function testTheRequestTimeoutCoversTheBudget(): void
    {
        self::assertGreaterThan(SetupFinisher::BUDGET_SECONDS, SetupFinisher::REQUIRED_REQUEST_TIMEOUT);
    }

    private function finisherWithLocks(LockFactory $locks): SetupFinisher
    {
        return new SetupFinisher(
            $this->fx->settings,
            $this->fx->users,
            $this->fx->keys,
            $this->fx->usersFile,
            $this->fx->system,
            $locks,
            $this->fx->clock,
            new \Psr\Log\NullLogger(),
        );
    }
}
