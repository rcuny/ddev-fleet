<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Ssh\SshKey;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SshKeyRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private SshKeyRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SshKeyRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testOneKeyPerRole(): void
    {
        self::assertNull($this->repository->findByRole(SshKeyRole::Deploy));

        $this->repository->save(new SshKey(SshKeyRole::Deploy, 'ssh-ed25519 AAAA deploy', 'SHA256:abc'));
        $this->repository->save(new SshKey(SshKeyRole::Push, 'ssh-ed25519 BBBB push', 'SHA256:def'));
        $this->entityManager()->clear();

        $deploy = $this->repository->findByRole(SshKeyRole::Deploy);
        self::assertNotNull($deploy);
        self::assertSame('ssh-ed25519 AAAA deploy', $deploy->publicKey());
        self::assertSame('SHA256:abc', $deploy->fingerprint());
        self::assertSame(SshKeyRole::Push, $this->repository->findByRole(SshKeyRole::Push)?->role());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save(new SshKey(SshKeyRole::Deploy, 'ssh-ed25519 CCCC other', 'SHA256:ghi'));
    }

    public function testReplaceKeyUpdatesTheExistingRow(): void
    {
        $this->repository->save(new SshKey(SshKeyRole::Deploy, 'ssh-ed25519 AAAA', 'SHA256:abc'));
        $key = $this->repository->findByRole(SshKeyRole::Deploy);
        self::assertNotNull($key);

        $key->replaceKey('ssh-ed25519 ZZZZ', 'SHA256:zzz');
        $this->repository->save($key);
        $this->entityManager()->clear();

        self::assertSame('SHA256:zzz', $this->repository->findByRole(SshKeyRole::Deploy)?->fingerprint());
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ssh_key'));
    }
}
