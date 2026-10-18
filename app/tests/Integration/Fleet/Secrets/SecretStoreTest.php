<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SealedSecret;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SecretStoreTest extends KernelTestCase
{
    use FleetDatabase;

    private SecretStore $store;
    private SecretBox $box;
    private MockClock $clock;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->box = new SecretBox(new SecretKeyFile(self::getContainer()->get(FleetPaths::class)));
        $this->store = new SecretStore(new SecretRepository($em), $this->box, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testPutGetRoundTrip(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'tok-123', 'admin');
        $this->entityManager()->clear();

        self::assertSame('tok-123', $this->store->getFleetWide('claude_oauth_token'));
        self::assertTrue($this->store->hasFleetWide('claude_oauth_token'));
        self::assertNull($this->store->getFleetWide('ovh_consumer_key'));
        self::assertFalse($this->store->hasFleetWide('ovh_consumer_key'));
    }

    public function testFleetScopeUsesAnEmptyScopeId(): void
    {
        $this->store->putFleetWide('ovh_endpoint', 'ovh-eu', 'admin');

        $row = $this->entityManager()->getConnection()->fetchAssociative('SELECT scope, scope_id, key_version FROM secret');
        self::assertSame(['scope' => 'fleet', 'scope_id' => '', 'key_version' => 1], $row);
    }

    public function testUpsertUpdatesUpdatedByAndAt(): void
    {
        $this->store->putFleetWide('ovh_application_key', 'one', 'alice');
        $this->clock->modify('+1 hour');
        $this->store->putFleetWide('ovh_application_key', 'two', 'bob');
        $this->entityManager()->clear();

        self::assertSame('two', $this->store->getFleetWide('ovh_application_key'));
        $connection = $this->entityManager()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM secret'));
        $row = $connection->fetchAssociative('SELECT updated_by, updated_at FROM secret');
        self::assertSame(['updated_by' => 'bob', 'updated_at' => '2026-10-19 11:00:00'], $row);
    }

    public function testUpsertSealsWithAFreshNonce(): void
    {
        $this->store->putFleetWide('ovh_application_key', 'same', 'admin');
        $first = (string) $this->entityManager()->getConnection()->fetchOne('SELECT nonce FROM secret');
        $this->store->putFleetWide('ovh_application_key', 'same', 'admin');
        $second = (string) $this->entityManager()->getConnection()->fetchOne('SELECT nonce FROM secret');

        self::assertSame(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, \strlen($first));
        self::assertNotSame($first, $second);
    }

    public function testDatabaseNeverHoldsPlaintext(): void
    {
        $plaintext = 'very-secret-plaintext-value';
        $this->store->putFleetWide('ovh_application_secret', $plaintext, 'admin');

        $connection = $this->entityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT ciphertext, nonce, key_version FROM secret');
        self::assertIsArray($row);
        self::assertStringNotContainsString($plaintext, (string) $row['ciphertext']);
        self::assertStringNotContainsString($plaintext, (string) $row['nonce']);
        foreach ($connection->fetchAllAssociative('SELECT * FROM secret') as $all) {
            foreach ($all as $value) {
                self::assertStringNotContainsString($plaintext, (string) $value);
            }
        }
        self::assertSame(
            $plaintext,
            $this->box->open(new SealedSecret((string) $row['ciphertext'], (string) $row['nonce'], (int) $row['key_version'])),
        );
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameRejected(string $name): void
    {
        foreach ([
            fn () => $this->store->putFleetWide($name, 'x', 'admin'),
            fn () => $this->store->getFleetWide($name),
            fn () => $this->store->hasFleetWide($name),
            fn () => $this->store->forgetFleetWide($name),
        ] as $call) {
            try {
                $call();
                self::fail('Name "'.$name.'" must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM secret'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'one char' => ['a'];
        yield 'uppercase' => ['Token'];
        yield 'leading digit' => ['1token'];
        yield 'leading underscore' => ['_token'];
        yield 'dash' => ['my-token'];
        yield 'space' => ['my token'];
        yield 'trailing newline' => ["token\n"];
        yield 'too long' => ['a'.str_repeat('b', 64)];
    }

    public function testNameOfExactlyMaxLengthIsAccepted(): void
    {
        $name = 'a'.str_repeat('b', 63);

        $this->store->putFleetWide($name, 'x', 'admin');

        self::assertSame('x', $this->store->getFleetWide($name));
    }

    public function testInvalidUpdaterRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->putFleetWide('ovh_endpoint', 'x', '');
    }

    public function testForgetRemoves(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'tok', 'admin');

        $this->store->forgetFleetWide('claude_oauth_token');
        $this->store->forgetFleetWide('claude_oauth_token');

        self::assertFalse($this->store->hasFleetWide('claude_oauth_token'));
        self::assertNull($this->store->getFleetWide('claude_oauth_token'));
    }

    public function testCorruptedRowFailsExplicitlyWithoutLeakingData(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'tok-secret', 'admin');
        $repository = new SecretRepository($this->entityManager());
        $secret = $repository->find(Secret::SCOPE_FLEET, '', 'claude_oauth_token');
        self::assertInstanceOf(Secret::class, $secret);
        $flipped = $secret->ciphertext();
        $flipped = substr_replace($flipped, \chr(\ord($flipped[0]) ^ 1), 0, 1);
        $secret->replace($flipped, $secret->nonce(), 1, 'admin');
        $repository->save($secret);
        $this->entityManager()->clear();

        try {
            $this->store->getFleetWide('claude_oauth_token');
            self::fail('A corrupted secret must not open.');
        } catch (SecretOpenFailed $e) {
            self::assertStringNotContainsString('tok-secret', $e->getMessage());
            self::assertStringContainsString('claude_oauth_token', $e->getMessage());
        }
    }

    public function testAnUnknownKeyVersionInTheDatabaseFails(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'tok', 'admin');
        $this->entityManager()->getConnection()->executeStatement('UPDATE secret SET key_version = 2');
        $this->entityManager()->clear();

        $this->expectException(SecretOpenFailed::class);
        $this->store->getFleetWide('claude_oauth_token');
    }
}
