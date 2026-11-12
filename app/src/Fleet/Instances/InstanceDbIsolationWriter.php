<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Network isolation of an instance's service containers (FLE-297, reworked in FLE-307).
 *
 * DDEV attaches every `db` container to the shared `ddev_default` network with the alias `db` (and its container name),
 * so a web container of ANOTHER project, also on `ddev_default`, resolves `db` or `ddev-<project>-db` to someone else's
 * database and reaches it with DDEV's default credentials (db/db). Only `web` (and a service the router publishes) needs
 * `ddev_default`.
 *
 * The mechanism is {@see self::converge()}: after every start, restart and router repair, and on every watcher pass, it
 * disconnects the project's non-web containers (db, mailpit, typesense, solr, redis...) from `ddev_default` when attached,
 * except a non-db service carrying a traefik router label key (the db is always detached, whatever its labels). A compose override cannot do it: DDEV connects the db container to `ddev_default`
 * itself, outside the compose merge (live finding FLE-307, DDEV 1.25.5, the `!override` file was ignored), so no override
 * is written for an instance with a db and a stale one is removed. An instance WITHOUT a db (`omit_containers: [db]`)
 * still gets a compose file that pins `db` and `ddev-<project>-db` to 127.0.0.1 in EVERY service, so the names can never
 * resolve to another project's database.
 *
 * The disconnect is logged as an info line and audited once per event; the job fails only when the disconnect fails or the
 * container is still attached afterwards.
 */
final readonly class InstanceDbIsolationWriter
{
    public const string RELATIVE_PATH = '.ddev/docker-compose.zzz-fleet-isolation.yaml';
    /** The name of the first (FLE-297) version of the file; removed on every write. */
    public const string LEGACY_PATH = '.ddev/docker-compose.fleet-db-isolation.yaml';
    public const string MARKER = '# fleet-managed: instance database isolation (FLE-297)';
    public const string SHARED_NETWORK = 'ddev_default';
    public const string LOCK_ROOT_SQL = "ALTER USER IF EXISTS 'root'@'%' ACCOUNT LOCK";
    public const string SOCKET_USER_SQL_MARIADB = "CREATE USER IF NOT EXISTS 'mysql'@'localhost' IDENTIFIED VIA unix_socket; GRANT ALL ON *.* TO 'mysql'@'localhost' WITH GRANT OPTION";
    public const string SOCKET_USER_SQL_MYSQL = "CREATE USER IF NOT EXISTS 'mysql'@'localhost' IDENTIFIED WITH auth_socket; GRANT ALL ON *.* TO 'mysql'@'localhost' WITH GRANT OPTION";

    public function __construct(private Ddev $ddev, private ?AuditLog $audit = null)
    {
    }

    /**
     * @param list<string> $services
     *
     * @return array<string, mixed> the compose document for these services
     */
    public static function render(array $services, string $project): array
    {
        if (\in_array('db', $services, true)) {
            return [];
        }
        $pins = ['db:127.0.0.1', 'ddev-'.$project.'-db:127.0.0.1'];
        $document = [];
        foreach ($services as $service) {
            $document[$service] = ['extra_hosts' => $pins];
        }

        return ['services' => $document];
    }

    /**
     * Writes the override. Call it before EVERY `ddev start` or `ddev restart`.
     *
     * @param \Closure(string): void $log
     *
     * @return string|null the path written, relative to $dir (null: an instance with a db needs no file)
     *
     * @throws \RuntimeException when the services cannot be listed or the file cannot be written
     */
    public function write(string $dir, string $project, \Closure $log): ?string
    {
        $services = $this->ddev->composeServices($dir);
        if (is_file($dir.'/'.self::LEGACY_PATH)) {
            @unlink($dir.'/'.self::LEGACY_PATH);
        }
        $document = self::render($services, $project);
        if ([] === $document) {
            // A db instance: converge() does the work after the start; the FLE-297 override file did nothing (FLE-307).
            if (is_file($dir.'/'.self::RELATIVE_PATH)) {
                @unlink($dir.'/'.self::RELATIVE_PATH);
                $log('removed '.self::RELATIVE_PATH.': the isolation is done by disconnecting the containers after each start');
            }

            return null;
        }
        AtomicFile::write($dir.'/'.self::RELATIVE_PATH, self::MARKER."\n".Yaml::dump($document, 6, 2, Yaml::DUMP_OBJECT_AS_MAP), 0o640);
        $log('wrote '.self::RELATIVE_PATH.': no db container, "db" is pinned to 127.0.0.1 in '.\count($services).' service(s)');

        return self::RELATIVE_PATH;
    }

    /**
     * The containers of the project that are on ddev_default although only `web` (and a routed service) belongs there.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when docker cannot be asked
     */
    public function exposed(string $project): array
    {
        try {
            $containers = $this->ddev->projectContainers($project);
        } catch (DdevUnavailable $e) {
            throw new \RuntimeException('Cannot verify the isolation of '.$project.': '.$e->getMessage(), 0, $e);
        }
        $exposed = [];
        foreach ($containers as $container) {
            if ('web' === $container['service'] || ('db' !== $container['service'] && $container['routed'])) {
                continue;
            }
            $networks = $this->ddev->containerNetworks($container['name']);
            if (null !== $networks && \in_array(self::SHARED_NETWORK, $networks, true)) {
                $exposed[] = $container['name'];
            }
        }

        return $exposed;
    }

    /**
     * The mechanism (FLE-307). Call it after EVERY successful start or restart, after a router repair start and on every
     * watcher pass: every container of the project except `web` (and any container the router publishes) that is on
     * ddev_default is disconnected, logged and audited once. Idempotent; a project without containers is a no-op.
     *
     * @param \Closure(string): void $log
     *
     * @return list<string> the containers disconnected
     *
     * @throws \RuntimeException when a disconnect fails, a container is still attached after it, or docker cannot be asked
     */
    public function converge(string $project, \Closure $log): array
    {
        $cut = [];
        foreach ($this->exposed($project) as $name) {
            if (!$this->ddev->disconnectNetwork(self::SHARED_NETWORK, $name)) {
                $log('SECURITY: '.$name.' is on '.self::SHARED_NETWORK.' and could not be disconnected');
                throw new \RuntimeException($name.' is attached to '.self::SHARED_NETWORK.' and disconnecting it failed: stop the instance or run "docker network disconnect '.self::SHARED_NETWORK.' '.$name.'".');
            }
            $left = $this->ddev->containerNetworks($name);
            if (null !== $left && \in_array(self::SHARED_NETWORK, $left, true)) {
                $log('SECURITY: '.$name.' is still on '.self::SHARED_NETWORK.' after the disconnect');
                throw new \RuntimeException($name.' is still attached to '.self::SHARED_NETWORK.' after it was disconnected.');
            }
            $log('isolation: disconnected '.$name.' from '.self::SHARED_NETWORK.' (DDEV attaches it there itself)');
            $cut[] = $name;
        }
        if ([] !== $cut) {
            $this->audit?->record('system', AuditLog::DB_ISOLATION_DISCONNECT, $project.': '.implode(', ', $cut));
        }

        return $cut;
    }

    /**
     * FLE-324 (owner decision 2026-11-12, option 2): DDEV's `db` user (db/db) stays, because settings.ddev.php, `ddev mysql`,
     * import-db, export-db, the healthcheck and snapshots assume it. Only root is closed to the network: the account
     * `root@'%'` (password "root" in every DDEV db container) is locked, so nothing that reaches the database over the
     * network, or from the host's published port, gets DBA rights. `root@localhost`, used inside the db container
     * (healthcheck, `ddev mysql`, snapshot, import-db's DROP/CREATE), is left alone. No password is chosen or stored: a
     * locked account needs none. Idempotent, so it runs after every start. Never fatal: a database server that does not know
     * ACCOUNT LOCK (MariaDB before 10.4) only logs a warning.
     *
     * @param \Closure(string): void $log
     *
     * @return bool true when the lock statement ran
     */
    public function lockRoot(string $project, \Closure $log): bool
    {
        try {
            $container = null;
            foreach ($this->ddev->projectContainers($project) as $row) {
                if ('db' === $row['service']) {
                    $container = $row['name'];
                }
            }
            if (null === $container) {
                return false; // no db container (omit_containers: [db]): nothing to lock
            }
            $result = $this->ddev->dbSql($container, self::LOCK_ROOT_SQL);
        } catch (\Throwable $e) {
            $log('WARNING: isolation: root@% of the database was not locked: '.$e->getMessage());

            return false;
        }
        if (!$result->isSuccess()) {
            $log('WARNING: isolation: root@% of the database was not locked (exit code '.$result->exitCode.'): '.trim(substr($result->stderr, 0, 300)));

            return false;
        }
        $log('isolation: database account root@% is locked (db/db and root@localhost are unchanged)');

        return true;
    }

    /**
     * FLE-331: the host user `fleet` is uid 999, which is the `mysql` account inside DDEV's db image. DDEV runs `ddev mysql`,
     * export-db and import-db in the db container as the host uid, so they connect over the socket as `mysql@localhost`; that
     * account does not exist, MariaDB falls back to the anonymous `''@localhost` (no privileges) and answers ERROR 1044.
     * The fix is the missing account: `mysql@localhost`, unix_socket only (usable by the OS user `mysql` inside the db
     * container, the same power as the `root@localhost` socket account that already exists, so no new network exposure), with
     * ALL on `*.*` because import-db drops and creates the database. MariaDB syntax first, then MySQL's auth_socket. The
     * anonymous users are left alone: dropping them only turns 1044 into 1045. Idempotent, runs after every start, never fatal.
     *
     * @param \Closure(string): void $log
     *
     * @return bool true when the account exists after the call
     */
    public function ensureSocketUser(string $project, \Closure $log): bool
    {
        try {
            $container = null;
            foreach ($this->ddev->projectContainers($project) as $row) {
                if ('db' === $row['service']) {
                    $container = $row['name'];
                }
            }
            if (null === $container) {
                return false; // no db container (omit_containers: [db])
            }
            $result = $this->ddev->dbSql($container, self::SOCKET_USER_SQL_MARIADB);
            if (!$result->isSuccess()) {
                $result = $this->ddev->dbSql($container, self::SOCKET_USER_SQL_MYSQL);
            }
        } catch (\Throwable $e) {
            $log('WARNING: database account mysql@localhost was not ensured: '.$e->getMessage());

            return false;
        }
        if (!$result->isSuccess()) {
            $log('WARNING: database account mysql@localhost was not ensured, ddev export-db / import-db / mysql may fail with ERROR 1044 (exit code '.$result->exitCode.'): '.trim(substr($result->stderr, 0, 300)));

            return false;
        }
        $log('database account mysql@localhost (unix_socket) is in place: ddev mysql, export-db and import-db work for the fleet user (uid 999)');

        return true;
    }
}
