<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SecretRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private SecretRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SecretRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private static function secret(string $name, string $ciphertext = 'c', string $nonce = 'n'): Secret
    {
        return new Secret(Secret::SCOPE_FLEET, '', $name, $ciphertext, $nonce, 1, 'wizard');
    }

    public function testFleetScopeNameIsUnique(): void
    {
        $this->repository->save(self::secret('ovh_application_key'));
        $this->repository->save(self::secret('claude_oauth_token'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save(self::secret('ovh_application_key'));
    }

    public function testFindAndRemove(): void
    {
        $this->repository->save(self::secret('claude_oauth_token'));
        $this->entityManager()->clear();

        $found = $this->repository->find('fleet', '', 'claude_oauth_token');
        self::assertNotNull($found);
        self::assertSame('wizard', $found->updatedBy());
        self::assertNull($this->repository->find('fleet', '', 'missing'));
        self::assertNull($this->repository->find('project', 'acme', 'claude_oauth_token'));

        $this->repository->remove($found);
        $this->entityManager()->clear();
        self::assertNull($this->repository->find('fleet', '', 'claude_oauth_token'));
    }

    public function testBlobsRoundTripBinarySafe(): void
    {
        $ciphertext = random_bytes(200)."\0\xff\x00end";
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $this->repository->save(self::secret('binary', $ciphertext, $nonce));
        $this->entityManager()->clear();

        $loaded = $this->repository->find('fleet', '', 'binary');
        self::assertNotNull($loaded);
        self::assertSame($ciphertext, $loaded->ciphertext());
        self::assertSame($nonce, $loaded->nonce());
        self::assertSame(1, $loaded->keyVersion());

        $loaded->replace('new', 'nonce2', 2, 'admin');
        $this->repository->save($loaded);
        $this->entityManager()->clear();
        $again = $this->repository->find('fleet', '', 'binary');
        self::assertNotNull($again);
        self::assertSame('new', $again->ciphertext());
        self::assertSame(2, $again->keyVersion());
        self::assertSame('admin', $again->updatedBy());
    }
}
