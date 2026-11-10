<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Api\Token\TokenFormat;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** fleet:token:create, list, revoke and rotate: the secret goes to stdout only, once; everything else to stderr. */
final class TokenCommandsTest extends KernelTestCase
{
    use FleetDatabase;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exec(string $name, array $input): CommandTester
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function create(string $name = 'orch'): CommandTester
    {
        return $this->exec('fleet:token:create', ['name' => $name, '--scope' => ['read', 'lifecycle'], '--project' => ['blog'], '--ip' => ['192.0.2.0/24']]);
    }

    public function testCreatePrintsOnlyTheSecretOnStdoutAndAuditsAsCli(): void
    {
        $tester = $this->create();

        self::assertSame(0, $tester->getStatusCode());
        $secret = trim($tester->getDisplay());
        self::assertTrue(TokenFormat::isWellFormed($secret));
        self::assertStringNotContainsString($secret, $tester->getErrorOutput());
        $entries = self::getContainer()->get(AuditLog::class)->latest(5, AuditLog::API_TOKEN_CREATE);
        self::assertSame('cli', $entries[0]->actor());
        $stored = self::getContainer()->get(ApiTokenRepository::class)->findByHash(TokenFormat::hash($secret));
        self::assertNotNull($stored);
        // lifecycle default: 30 days
        self::assertEqualsWithDelta(30 * 86400, $stored->expiresAt()->getTimestamp() - time(), 120);
    }

    public function testCreateRefusesWhatThePolicyRefuses(): void
    {
        $tester = $this->exec('fleet:token:create', ['name' => 'term', '--scope' => ['terminal:write'], '--project' => ['blog']]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('IP allow-list', $tester->getErrorOutput());
        self::assertSame('', trim($tester->getDisplay()));
    }

    public function testCreateRefusesAnUnknownScopeAndABadLifetime(): void
    {
        $bad = $this->exec('fleet:token:create', ['name' => 'x', '--scope' => ['root'], '--project' => ['blog']]);
        self::assertSame(2, $bad->getStatusCode());
        $days = $this->exec('fleet:token:create', ['name' => 'x', '--scope' => ['read'], '--project' => ['blog'], '--expires-in' => '-3']);
        self::assertSame(2, $days->getStatusCode());
        $long = $this->exec('fleet:token:create', ['name' => 'x', '--scope' => ['read'], '--project' => ['blog'], '--expires-in' => '400']);
        self::assertSame(1, $long->getStatusCode());
    }

    public function testListNeverShowsTheSecretAndFlagsUnusedAndExpiringTokens(): void
    {
        $secret = trim($this->create()->getDisplay());
        $this->exec('fleet:token:create', ['name' => 'soon', '--scope' => ['read'], '--project' => ['*'], '--expires-in' => '5']);

        $json = json_decode($this->exec('fleet:token:list', ['--json' => true])->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(1, $json['schema']);
        self::assertCount(2, $json['tokens']);
        self::assertStringNotContainsString($secret, json_encode($json, \JSON_THROW_ON_ERROR));
        $byName = array_column($json['tokens'], null, 'name');
        self::assertTrue($byName['soon']['expiring']);
        self::assertFalse($byName['orch']['expiring']);
        self::assertFalse($byName['soon']['unused']);
        self::assertStringContainsString('EXPIRING', $this->exec('fleet:token:list', [])->getDisplay());
    }

    public function testRevokeThenListHidesItUnlessAll(): void
    {
        $this->create();

        $revoke = $this->exec('fleet:token:revoke', ['id' => '1']);
        self::assertSame(0, $revoke->getStatusCode());
        self::assertSame('No tokens.', trim($this->exec('fleet:token:list', [])->getDisplay()));
        self::assertStringContainsString('revoked', $this->exec('fleet:token:list', ['--all' => true])->getDisplay());
        self::assertSame(1, $this->exec('fleet:token:revoke', ['id' => '99'])->getStatusCode());
        self::assertSame('Already revoked.', trim($this->exec('fleet:token:revoke', ['id' => '1'])->getDisplay()));
    }

    public function testRotateIssuesASuccessorAndRefusesARevokedToken(): void
    {
        $old = trim($this->create()->getDisplay());

        $rotate = $this->exec('fleet:token:rotate', ['id' => '1']);
        $new = trim($rotate->getDisplay());
        self::assertSame(0, $rotate->getStatusCode());
        self::assertNotSame($old, $new);
        self::assertTrue(TokenFormat::isWellFormed($new));
        $tokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertTrue($tokens->findByHash(TokenFormat::hash($old))?->isRevoked());
        self::assertSame(1, $tokens->findByHash(TokenFormat::hash($new))?->rotatedFrom());

        $again = $this->exec('fleet:token:rotate', ['id' => '1']);
        self::assertSame(1, $again->getStatusCode());
        self::assertStringContainsString('revoked', $again->getErrorOutput());
        self::assertSame(2, $this->exec('fleet:token:rotate', ['id' => '2', '--grace' => '9999'])->getStatusCode());
    }
}
