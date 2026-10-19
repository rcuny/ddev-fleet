<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The managed secrets (UPPER_CASE names, fleet or project scope) of SecretStore.
 */
final class SecretStoreManagedTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private SecretStore $store;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->store = new SecretStore(
            new SecretRepository($this->em),
            new SecretBox(new SecretKeyFile(self::getContainer()->get(FleetPaths::class))),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testRoundTripInBothScopes(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'fleet-value', 'alice');
        $this->store->putManaged('blog', 'API_KEY', 'blog-value', 'alice');
        $this->em->clear();

        self::assertSame('fleet-value', $this->store->getManaged(null, 'API_KEY'));
        self::assertSame('blog-value', $this->store->getManaged('blog', 'API_KEY'));
        self::assertNull($this->store->getManaged('shop', 'API_KEY'));
        self::assertTrue($this->store->hasManaged('blog', 'API_KEY'));
        self::assertFalse($this->store->hasManaged('shop', 'API_KEY'));
    }

    public function testScopesAreStoredAsScopeAndScopeId(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'a-value-1', 'alice');
        $this->store->putManaged('blog', 'API_KEY', 'b-value-1', 'alice');

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT scope, scope_id, name FROM secret ORDER BY scope');

        self::assertSame([
            ['scope' => 'fleet', 'scope_id' => '', 'name' => 'API_KEY'],
            ['scope' => 'project', 'scope_id' => 'blog', 'name' => 'API_KEY'],
        ], $rows);
    }

    public function testReplaceKeepsOneRowAndStampsWhoAndWhen(): void
    {
        $this->store->putManaged('blog', 'API_KEY', 'first-value', 'alice');
        $this->clock->modify('+1 hour');
        $this->store->putManaged('blog', 'API_KEY', 'second-value', 'bob');
        $this->em->clear();

        self::assertSame('second-value', $this->store->getManaged('blog', 'API_KEY'));
        $list = $this->store->listManaged('blog');
        self::assertCount(1, $list);
        self::assertSame('bob', $list[0]->updatedBy);
        self::assertSame('2026-10-19 11:00:00', $list[0]->updatedAt->format('Y-m-d H:i:s'));
    }

    public function testListIsOrderedByNameAndNeverHoldsAValue(): void
    {
        $this->store->putManaged('blog', 'ZED', 'z-value-1', 'alice');
        $this->store->putManaged('blog', 'ALPHA', 'a-value-1', 'alice');
        $this->store->putManaged(null, 'FLEET_ONE', 'f-value-1', 'alice');

        $list = $this->store->listManaged('blog');

        self::assertSame(['ALPHA', 'ZED'], array_map(static fn ($s): string => $s->name, $list));
        self::assertSame('[[alpha]]', $list[0]->placeholder());
        self::assertSame(['FLEET_ONE'], array_map(static fn ($s): string => $s->name, $this->store->listManaged(null)));
        self::assertStringNotContainsString('a-value-1', serialize($list));
        self::assertStringNotContainsString('z-value-1', serialize($list));
    }

    public function testManagedAndHostSecretsDoNotMix(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'host-value-1', 'setup');
        $this->store->putManaged(null, 'API_KEY', 'managed-value-1', 'alice');

        self::assertSame(['API_KEY'], array_map(static fn ($s): string => $s->name, $this->store->listManaged(null)));
        self::assertSame(['claude_oauth_token'], array_map(static fn ($s): string => $s->name, $this->store->listHost()));
    }

    public function testForget(): void
    {
        $this->store->putManaged('blog', 'API_KEY', 'value-1', 'alice');
        $this->store->putManaged(null, 'API_KEY', 'value-2', 'alice');

        self::assertTrue($this->store->forgetManaged('blog', 'API_KEY'));
        self::assertFalse($this->store->forgetManaged('blog', 'API_KEY'));

        self::assertNull($this->store->getManaged('blog', 'API_KEY'));
        self::assertSame('value-2', $this->store->getManaged(null, 'API_KEY'));
    }

    public function testForgetAllOfProjectLeavesOtherScopes(): void
    {
        $this->store->putManaged('blog', 'ONE', 'value-1', 'alice');
        $this->store->putManaged('blog', 'TWO', 'value-2', 'alice');
        $this->store->putManaged('shop', 'THREE', 'value-3', 'alice');
        $this->store->putManaged(null, 'FOUR', 'value-4', 'alice');

        self::assertSame(['ONE', 'TWO'], $this->store->forgetAllOfProject('blog'));
        self::assertSame([], $this->store->forgetAllOfProject('blog'));

        self::assertSame([], $this->store->listManaged('blog'));
        self::assertCount(1, $this->store->listManaged('shop'));
        self::assertCount(1, $this->store->listManaged(null));
    }

    public function testTheDatabaseNeverHoldsPlaintext(): void
    {
        $plaintext = 'very-secret-managed-plaintext';
        $this->store->putManaged('blog', 'API_KEY', $plaintext, 'alice');

        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM secret') as $row) {
            foreach ($row as $value) {
                self::assertStringNotContainsString($plaintext, (string) $value);
            }
        }
    }

    public function testACiphertextMovedToAnotherScopeDoesNotOpen(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'fleet-value-1', 'alice');
        $this->store->putManaged('blog', 'API_KEY', 'blog-value-1', 'alice');
        $connection = $this->em->getConnection();
        $fleet = $connection->fetchAssociative("SELECT ciphertext, nonce FROM secret WHERE scope = 'fleet'");
        self::assertIsArray($fleet);
        $connection->executeStatement("UPDATE secret SET ciphertext = ?, nonce = ? WHERE scope = 'project'", [$fleet['ciphertext'], $fleet['nonce']]);
        $this->em->clear();

        $this->expectException(SecretOpenFailed::class);
        $this->expectExceptionMessage('"API_KEY" could not be opened');

        $this->store->getManaged('blog', 'API_KEY');
    }

    public function testAnOpenFailureNeverLeaksTheValue(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'leaky-value-123', 'alice');
        $this->em->getConnection()->executeStatement('UPDATE secret SET ciphertext = zeroblob(40)');
        $this->em->clear();

        try {
            $this->store->getManaged(null, 'API_KEY');
            self::fail('expected SecretOpenFailed');
        } catch (SecretOpenFailed $e) {
            self::assertStringNotContainsString('leaky-value-123', $e->getMessage().$e->getTraceAsString());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'lowercase' => ['api_key'];
        yield 'dash' => ['API-KEY'];
        yield 'leading digit' => ['1KEY'];
        yield 'too long' => [str_repeat('A', 65)];
        yield 'trailing newline' => ["KEY\n"];
        yield 'host style name' => ['claude_oauth_token'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRejectedEverywhere(string $name): void
    {
        foreach ([
            fn () => $this->store->putManaged(null, $name, 'value-1', 'alice'),
            fn () => $this->store->getManaged('blog', $name),
            fn () => $this->store->hasManaged(null, $name),
            fn () => $this->store->forgetManaged(null, $name),
        ] as $call) {
            try {
                $call();
                self::fail('A name that is not a managed secret name must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValueLimits(): void
    {
        $this->store->putManaged(null, 'BIG', str_repeat('x', SecretStore::MAX_VALUE_BYTES), 'alice');
        self::assertSame(SecretStore::MAX_VALUE_BYTES, \strlen((string) $this->store->getManaged(null, 'BIG')));

        foreach (['', str_repeat('x', SecretStore::MAX_VALUE_BYTES + 1)] as $value) {
            try {
                $this->store->putManaged(null, 'BAD', $value, 'alice');
                self::fail('expected a refusal');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('xxxx', $e->getMessage());
            }
        }
        self::assertFalse($this->store->hasManaged(null, 'BAD'));
    }

    public function testTheUpdaterMustBeOneToSixtyFourBytes(): void
    {
        foreach (['', str_repeat('a', 65)] as $by) {
            try {
                $this->store->putManaged(null, 'API_KEY', 'value-1', $by);
                self::fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASensitiveValueStaysOutOfTheExceptionTrace(): void
    {
        try {
            $this->store->putManaged(null, 'bad name', 'trace-leak-value-123', 'alice');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('trace-leak-value-123', $e->getTraceAsString());
        }
    }
}
