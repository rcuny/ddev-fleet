<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SealedSecret;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Events;
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
        self::assertSame(['scope' => 'fleet', 'scope_id' => '', 'key_version' => 2], $row);
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

        self::assertSame(SecretBox::NONCE_BYTES, \strlen($first));
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
            $this->box->open(
                new SealedSecret((string) $row['ciphertext'], (string) $row['nonce'], (int) $row['key_version']),
                SecretStore::context('fleet', '', 'ovh_application_secret'),
            ),
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
        $this->entityManager()->getConnection()->executeStatement('UPDATE secret SET key_version = 3');
        $this->entityManager()->clear();

        $this->expectException(SecretOpenFailed::class);
        $this->store->getFleetWide('claude_oauth_token');
    }

    public function testSwappedRowsDoNotOpen(): void
    {
        $this->store->putFleetWide('ovh_application_secret', 'ovh-value', 'admin');
        $this->store->putFleetWide('claude_oauth_token', 'claude-value', 'admin');
        $connection = $this->entityManager()->getConnection();
        $ovh = $connection->fetchAssociative("SELECT ciphertext, nonce FROM secret WHERE name = 'ovh_application_secret'");
        self::assertIsArray($ovh);

        // Someone with database access but no key copies one row's ciphertext over the other's.
        $connection->executeStatement(
            "UPDATE secret SET ciphertext = :c, nonce = :n WHERE name = 'claude_oauth_token'",
            ['c' => $ovh['ciphertext'], 'n' => $ovh['nonce']],
            ['c' => ParameterType::BINARY, 'n' => ParameterType::BINARY],
        );
        $this->entityManager()->clear();

        $this->expectException(SecretOpenFailed::class);
        $this->store->getFleetWide('claude_oauth_token');
    }

    public function testASealedValueIsBoundToItsScopeAndName(): void
    {
        $sealed = $this->box->seal('value', SecretStore::context('fleet', '', 'name_a'));

        self::assertSame('value', $this->box->open($sealed, SecretStore::context('fleet', '', 'name_a')));
        foreach ([
            SecretStore::context('fleet', '', 'name_b'),
            SecretStore::context('project', 'x', 'name_a'),
            SecretStore::context('fleet', 'x', 'name_a'),
        ] as $other) {
            try {
                $this->box->open($sealed, $other);
                self::fail('The ciphertext must not open under another (scope, scopeId, name).');
            } catch (SecretOpenFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testContextFramingCannotBeShiftedAcrossParts(): void
    {
        self::assertNotSame(SecretStore::context('ab', 'c', 'd'), SecretStore::context('a', 'bc', 'd'));
        self::assertNotSame(SecretStore::context('a', 'b', 'cd'), SecretStore::context('a', 'bc', 'd'));
        self::assertNotSame(SecretStore::context('a', '', 'b'), SecretStore::context('', 'a', 'b'));
        self::assertNotSame(SecretStore::context("a\0b", 'c', 'd'), SecretStore::context('a', "b\0c", 'd'));
    }

    public function testAConcurrentFirstPutMapsToACleanConflict(): void
    {
        $connection = $this->entityManager()->getConnection();
        $armed = true;
        $this->entityManager()->getEventManager()->addEventListener(
            [Events::preFlush],
            new class($connection, $armed) {
                public function __construct(private readonly Connection $connection, private bool &$armed)
                {
                }

                public function preFlush(): void
                {
                    if (!$this->armed) {
                        return;
                    }
                    $this->armed = false;
                    // The rival writer commits its row between our SELECT and our INSERT.
                    $this->connection->executeStatement(
                        "INSERT INTO secret (scope, scope_id, name, ciphertext, nonce, key_version, updated_by, updated_at)
                         VALUES ('fleet', '', 'ovh_endpoint', x'00', x'00', 2, 'rival', '2026-10-19 10:00:00')",
                    );
                }
            },
        );

        try {
            $this->store->putFleetWide('ovh_endpoint', 'ovh-eu', 'admin');
            self::fail('The lost race must surface as SecretConflict.');
        } catch (SecretConflict $e) {
            self::assertStringNotContainsString('ovh-eu', $e->getMessage());
            self::assertInstanceOf(UniqueConstraintViolationException::class, $e->getPrevious());
        }
    }

    public function testPlaintextStaysOutOfExceptionTraces(): void
    {
        $paths = self::getContainer()->get(FleetPaths::class);
        $paths->ensureLayout();
        file_put_contents($paths->secretKey(), 'x');
        chmod($paths->secretKey(), 0o640);

        try {
            $this->store->putFleetWide('claude_oauth_token', 'plaintext-in-a-frame', 'admin');
            self::fail('A wide-mode key file must stop the put.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('plaintext-in-a-frame', print_r($e->getTrace(), true));
        }
        self::assertCount(1, new \ReflectionMethod(SecretStore::class, 'putFleetWide')->getParameters()[1]->getAttributes(\SensitiveParameter::class));
    }
}
