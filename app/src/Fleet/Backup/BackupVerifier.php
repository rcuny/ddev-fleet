<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

use App\Fleet\FleetPaths;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks a database backup file without touching anything: the file is opened read-only, so the live database and the
 * file itself stay as they are. It asks SQLite for `PRAGMA integrity_check` and compares the migrations the file has
 * run with the ones this release ships (a file from an older release needs `doctrine:migrations:migrate` after a
 * restore; a file from a newer release must not be restored here).
 */
final readonly class BackupVerifier
{
    private const string MIGRATIONS_TABLE = 'doctrine_migration_versions';
    private const string HEADER = "SQLite format 3\0";

    public function __construct(
        private FleetPaths $paths,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the path is not a regular file to look at (missing, a link, the live database)
     */
    public function verify(string $path): BackupVerification
    {
        $real = realpath($path);
        if (false === $real || is_link($path) || !is_file($real) || !is_readable($real)) {
            throw new \InvalidArgumentException('The backup file does not exist or cannot be read.');
        }
        foreach ([$this->paths->fleetDb(), $this->paths->queueDb()] as $live) {
            if (is_file($live) && realpath($live) === $real) {
                throw new \InvalidArgumentException('This is the live database: verify a copy from the backups directory.');
            }
        }

        $problems = [];
        if (self::HEADER !== (string) file_get_contents($real, false, null, 0, \strlen(self::HEADER))) {
            return new BackupVerification($real, false, null, [], ['The file is not an SQLite database.']);
        }

        try {
            $pdo = new \PDO('sqlite:'.$real, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
            ]);
            $integrity = $this->integrity($pdo);
            if ('ok' !== $integrity) {
                $problems[] = 'integrity_check: '.$integrity;
            }
            [$status, $pending, $schemaProblems] = $this->schema($pdo, str_starts_with(basename($real), 'queue-') || str_starts_with(basename($real), 'queue.sqlite'));
        } catch (\PDOException $e) {
            return new BackupVerification($real, false, null, [], ['SQLite refused the file: '.$e->getMessage()]);
        }

        return new BackupVerification($real, [] === $problems, $status, $pending, [...$problems, ...$schemaProblems]);
    }

    private function integrity(\PDO $pdo): string
    {
        $rows = $pdo->query('PRAGMA integrity_check');
        $verdicts = false === $rows ? [] : $rows->fetchAll(\PDO::FETCH_COLUMN);
        if (['ok'] === $verdicts) {
            return 'ok';
        }

        return \sprintf('%d problem(s), first: %s', \count($verdicts), (string) ($verdicts[0] ?? 'no answer'));
    }

    /**
     * @return array{string|null, list<string>, list<string>} the status (current / behind), the migrations this release has and the file lacks, the problems
     */
    private function schema(\PDO $pdo, bool $queue): array
    {
        $table = $pdo->query(\sprintf("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '%s'", self::MIGRATIONS_TABLE));
        if (false === $table || false === $table->fetchColumn()) {
            // The queue database has no migrations: only its integrity says anything.
            return $queue ? [null, [], []] : [null, [], ['The file has no migrations table: it is not a fleet database.']];
        }

        $statement = $pdo->query(\sprintf('SELECT version FROM %s', self::MIGRATIONS_TABLE));
        /** @var list<string> $done */
        $done = false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        $shipped = array_map(
            static fn (AvailableMigration $migration): string => (string) $migration->getVersion(),
            array_values($this->migrations->getMigrationPlanCalculator()->getMigrations()->getItems()),
        );

        $unknown = array_diff($done, $shipped);
        if ([] !== $unknown) {
            return ['newer', [], [\sprintf('The file has run %d migration(s) this release does not ship: it comes from a newer release. Do not restore it here.', \count($unknown))]];
        }
        $pending = array_values(array_diff($shipped, $done));

        return [[] === $pending ? 'current' : 'behind', $pending, []];
    }
}
