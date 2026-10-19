<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\Secret\SecretRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Secrets\PlaceholderScanner;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SecretServiceTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private SecretStore $store;
    private SecretService $service;
    private FleetPaths $paths;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->paths = self::getContainer()->get(FleetPaths::class);
        $clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->store = new SecretStore(new SecretRepository($this->em), new SecretBox(new SecretKeyFile($this->paths)), $clock);
        $this->service = new SecretService(
            $this->store,
            new ProjectRepository($this->em),
            new AuditLog(new AuditEntryRepository($this->em), $clock),
            new PlaceholderScanner($this->paths),
        );
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function audit(): array
    {
        $this->em->clear();

        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em)->latest(50),
        );
    }

    public function testPutStoresAndAudits(): void
    {
        $this->service->put('blog', 'API_KEY', "tok-123\r\n", 'alice');

        self::assertSame('tok-123', $this->store->getManaged('blog', 'API_KEY'));
        self::assertSame([['alice', 'secret.put', 'project:blog/API_KEY']], $this->audit());
    }

    public function testPutFleetWideAudits(): void
    {
        $this->service->put(null, 'API_KEY', 'tok-123', 'alice');

        self::assertSame([['alice', 'secret.put', 'fleet/API_KEY']], $this->audit());
    }

    public function testPutCollectsBothFieldErrorsAndWritesNothing(): void
    {
        try {
            $this->service->put('blog', 'bad name', '"quoted"', 'alice');
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'value'], array_keys($e->errors));
            self::assertStringNotContainsString('quoted', json_encode($e->errors, \JSON_THROW_ON_ERROR).$e->getMessage());
        }

        self::assertSame([], $this->store->listManaged('blog'));
        self::assertSame([], $this->audit());
    }

    public function testPutRefusesNonStringInput(): void
    {
        foreach ([[['A'], 'x'], ['A', ['x']], [null, null], [5, 6]] as [$name, $value]) {
            try {
                $this->service->put(null, $name, $value, 'alice');
                self::fail('expected ValidationFailed');
            } catch (ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->audit());
    }

    public function testAnUnknownProjectIsNotFoundForEveryOperation(): void
    {
        foreach ([
            fn () => $this->service->overview('nope'),
            fn () => $this->service->put('nope', 'A', 'b', 'alice'),
            fn () => $this->service->delete('nope', 'A', 'alice'),
            fn () => $this->service->reveal('nope', 'A', 'alice'),
        ] as $call) {
            try {
                $call();
                self::fail('expected NotFound');
            } catch (NotFound $e) {
                self::assertStringContainsString('Project "nope" does not exist', $e->getMessage());
            }
        }
        self::assertSame([], $this->audit());
    }

    public function testRevealReturnsTheValueAndAudits(): void
    {
        $this->service->put('blog', 'API_KEY', 'reveal-me-123', 'alice');

        $value = $this->service->reveal('blog', 'API_KEY', 'bob');

        self::assertSame('reveal-me-123', $value);
        self::assertSame(['bob', 'secret.reveal', 'project:blog/API_KEY'], $this->audit()[0]);
        self::assertStringNotContainsString('reveal-me-123', json_encode($this->audit(), \JSON_THROW_ON_ERROR));
    }

    public function testRevealOfAMissingOrBadlyNamedSecretIsNotFoundAndNotAudited(): void
    {
        foreach (['MISSING', 'lowercase', '', "API_KEY\n"] as $name) {
            try {
                $this->service->reveal(null, $name, 'bob');
                self::fail('expected NotFound');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->audit());
    }

    public function testRevealOfACorruptedSecretIsNotAudited(): void
    {
        $this->service->put(null, 'API_KEY', 'value-1', 'alice');
        $this->em->getConnection()->executeStatement('UPDATE secret SET ciphertext = zeroblob(40)');
        $this->em->clear();

        $this->expectException(SecretOpenFailed::class);
        try {
            $this->service->reveal(null, 'API_KEY', 'bob');
        } finally {
            self::assertCount(1, $this->audit(), 'only the put is on record');
        }
    }

    public function testNothingIsRevealedWhenTheAuditEntryCannotBeWritten(): void
    {
        $this->service->put(null, 'API_KEY', 'never-handed-out', 'alice');
        $this->em->getConnection()->executeStatement('DROP TABLE audit_entry');

        try {
            $value = $this->service->reveal(null, 'API_KEY', 'bob');
            self::fail('the value must not be returned without an audit entry, got '.\strlen($value).' bytes');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('never-handed-out', $e->getMessage());
        }
    }

    public function testDeleteRemovesAndAudits(): void
    {
        $this->service->put('blog', 'API_KEY', 'value-1', 'alice');

        $this->service->delete('blog', 'API_KEY', 'bob');

        self::assertFalse($this->store->hasManaged('blog', 'API_KEY'));
        self::assertSame(['bob', 'secret.delete', 'project:blog/API_KEY'], $this->audit()[0]);
    }

    public function testDeleteOfAMissingSecretIsNotFoundAndNotAudited(): void
    {
        $this->expectException(NotFound::class);
        try {
            $this->service->delete('blog', 'MISSING', 'bob');
        } finally {
            self::assertSame([], $this->audit());
        }
    }

    public function testRemoveAllOfProjectAuditsEachSecret(): void
    {
        $this->service->put('blog', 'ONE', 'value-1', 'alice');
        $this->service->put('blog', 'TWO', 'value-2', 'alice');
        $this->service->put(null, 'KEEP', 'value-3', 'alice');

        $this->service->removeAllOfProject('blog', 'bob');

        self::assertSame([], $this->store->listManaged('blog'));
        self::assertCount(1, $this->store->listManaged(null));
        $deletes = array_values(array_filter($this->audit(), static fn (array $e): bool => 'secret.delete' === $e[1]));
        self::assertSame([['bob', 'secret.delete', 'project:blog/TWO'], ['bob', 'secret.delete', 'project:blog/ONE']], $deletes);
    }

    public function testOverviewMarksSecretsNoAssetFileUses(): void
    {
        $this->service->put('blog', 'USED', 'value-1', 'alice');
        $this->service->put('blog', 'UNUSED', 'value-2', 'alice');

        $noAssets = $this->service->overview('blog');
        self::assertNull($noAssets->referenced($noAssets->secrets[0]), 'unknown while there are no asset files');
        self::assertSame([], $noAssets->unreferenced());

        mkdir($this->paths->assetsDir('blog'), 0o750, true);
        file_put_contents($this->paths->assetsDir('blog').'/.env', 'X=[[used]]');
        $overview = $this->service->overview('blog');

        $byName = [];
        foreach ($overview->secrets as $secret) {
            $byName[$secret->name] = $overview->referenced($secret);
        }
        self::assertSame(['UNUSED' => false, 'USED' => true], $byName);
        self::assertSame(['UNUSED'], array_map(static fn ($s): string => $s->name, $overview->unreferenced()));
    }

    public function testFleetOverviewHasNoScanAndNeverFlags(): void
    {
        $this->service->put(null, 'API_KEY', 'value-1', 'alice');

        $overview = $this->service->overview(null);

        self::assertNull($overview->scan);
        self::assertNull($overview->referenced($overview->secrets[0]));
        self::assertSame([], $overview->unreferenced());
    }

    public function testTheAuditSubjectNamesTheScope(): void
    {
        self::assertSame('fleet/A', SecretService::subject(null, 'A'));
        self::assertSame('project:blog/A', SecretService::subject('blog', 'A'));
    }
}
