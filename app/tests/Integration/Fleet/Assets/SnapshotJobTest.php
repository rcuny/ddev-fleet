<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Assets;

use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Fleet\Assets\AssetStore;
use App\Fleet\Assets\SnapshotJob;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\NoLogMasker;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\JobTestCase;

final class SnapshotJobTest extends JobTestCase
{
    use InstanceFixtures;

    private RecordingCommandRunner $runner;
    private string $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new RecordingCommandRunner();
        $this->assets = $this->paths->assetsDir('blog');
    }

    private function job(): SnapshotJob
    {
        return new SnapshotJob($this->instances, new AssetStore($this->paths), new Ddev($this->runner, '/home/fleet'), $this->paths, $this->clock);
    }

    private function running(InstanceState $state = InstanceState::Running): Job
    {
        $this->instancesOf($this->projectNamed('blog'), [$state]);
        mkdir($this->paths->instanceDir('blog--i1'), 0o750, true);

        return new Job('snapshot', 'blog--i1', 'blog', JobTrigger::Ui, 'admin', [], null, false, null, $this->clock->now());
    }

    private function log(Job $job): JobLog
    {
        return new JobLog($this->paths->jobLog('1', 'blog--i1'), new NoLogMasker(), $this->feed, $job);
    }

    private ?string $envSeen = null;

    /** The effect of the credentials fallback: records the env file the script would source, writes the dump. */
    private function dumpsA(string $dir): \Closure
    {
        return function (array $argv) use ($dir): void {
            self::assertSame(1, preg_match('/> (\.fleet-snapshot-\w+\.sql\.gz)$/', $argv[2], $m));
            self::assertSame(1, preg_match('/^\. \.\/(\.fleet-snapshot-\w+\.env);/', $argv[2], $e));
            self::assertSame('0600', substr(\sprintf('%o', fileperms($dir.'/'.($e[1] ?? ''))), -4));
            $this->envSeen = (string) file_get_contents($dir.'/'.($e[1] ?? ''));
            file_put_contents($dir.'/'.($m[1] ?? ''), (string) gzencode('-- sql'));
        };
    }

    private function describes(string $type, string $user, string $password, string $name = 'app', string $port = ''): CommandResult
    {
        return new CommandResult(0, (string) json_encode(['raw' => ['dbinfo' => [
            'database_type' => $type, 'host' => 'db', 'dbname' => $name, 'username' => $user, 'password' => $password, 'dbPort' => $port,
        ]]]));
    }

    private function exportsA(string $content): \Closure
    {
        return static function (array $argv) use ($content): void {
            foreach ($argv as $arg) {
                if (str_starts_with($arg, '--file=')) {
                    file_put_contents(substr($arg, 7), (string) gzencode($content));
                }
            }
        };
    }

    public function testItExportsInTheCheckoutAndPublishesTheDump(): void
    {
        $job = $this->running();
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(0, "Wrote dump\n"), $this->exportsA('-- sql'));

        $this->job()->execute($job, $this->log($job));

        $calls = $this->runner->callsMatching(['ddev', 'export-db']);
        self::assertCount(1, $calls);
        self::assertSame($this->paths->instanceDir('blog--i1'), $calls[0]->cwd);
        self::assertSame(['ddev', 'export-db', '--gzip'], \array_slice($calls[0]->argv, 0, 3));
        self::assertStringStartsWith('--file='.$this->assets.'/dumps/.fleet-upload-', $calls[0]->argv[3]);
        self::assertSame('/home/fleet', $calls[0]->env['HOME']);

        self::assertSame(['default-blog--i1-20261020T090000Z.sql.gz'], array_map(basename(...), glob($this->assets.'/dumps/*') ?: []));
        self::assertSame([], glob($this->assets.'/dumps/.fleet-upload-*') ?: []);
        self::assertSame('-- sql', gzdecode((string) file_get_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z.sql.gz')));
        self::assertStringContainsString('Saved dumps/default-blog--i1-20261020T090000Z.sql.gz', (string) file_get_contents($this->paths->jobLog('1', 'blog--i1')));
    }

    public function testASecondSnapshotInTheSameSecondNeverOverwritesTheFirst(): void
    {
        $job = $this->running();
        mkdir($this->assets.'/dumps', 0o750, true);
        file_put_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z.sql.gz', 'first');
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(0), $this->exportsA('second'));

        $this->job()->execute($job, $this->log($job));

        self::assertSame('first', file_get_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z.sql.gz'));
        self::assertSame('second', gzdecode((string) file_get_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z-2.sql.gz')));
    }

    public function testAStoppedInstanceFailsBeforeAnyCommand(): void
    {
        $job = $this->running(InstanceState::Stopped);

        try {
            $this->job()->execute($job, $this->log($job));
            self::fail('Expected a failure.');
        } catch (JobFailed $e) {
            self::assertStringContainsString('not running', $e->getMessage());
        }
        self::assertSame([], $this->runner->calls());
        self::assertDirectoryDoesNotExist($this->assets.'/dumps');
    }

    public function testAnUnknownInstanceFails(): void
    {
        $job = new Job('snapshot', 'blog--nope', 'blog', JobTrigger::Ui, 'admin', [], null, false, null, $this->clock->now());

        $this->expectException(JobFailed::class);
        $this->job()->execute($job, $this->log($job));
    }

    public function testAMissingCheckoutFails(): void
    {
        $job = $this->running();
        rmdir($this->paths->instanceDir('blog--i1'));

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('no checkout');
        $this->job()->execute($job, $this->log($job));
    }

    public function testAFailedExportLeavesNothingAndKeepsTheOutputInTheLog(): void
    {
        $job = $this->running();
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(2, '', "Failed to export: db container is not running\n"), $this->exportsA('partial'));
        $this->runner->expect(['ddev', 'exec'], new CommandResult(2, '', "db unreachable\n"));

        try {
            $this->job()->execute($job, $this->log($job));
            self::fail('Expected a failure.');
        } catch (JobFailed $e) {
            self::assertSame('ddev export-db failed (exit 2): see the job log.', $e->getMessage());
            self::assertSame(2, $e->exitCode);
        }
        self::assertSame([], glob($this->assets.'/dumps/*') ?: []);
        self::assertSame([], glob($this->assets.'/dumps/.fleet-upload-*') ?: []);
        self::assertStringContainsString('db container is not running', (string) file_get_contents($this->paths->jobLog('1', 'blog--i1')));
    }

    public function testAFailedExportFallsBackToTheDdevDatabaseCredentials(): void
    {
        $job = $this->running();
        $dir = $this->paths->instanceDir('blog--i1');
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(1, '', "access denied\n"));
        $this->runner->expect(['ddev', 'exec'], new CommandResult(0), $this->dumpsA($dir));

        $this->job()->execute($job, $this->log($job));

        $exec = $this->runner->callsMatching(['ddev', 'exec']);
        self::assertCount(1, $exec);
        self::assertStringContainsString('-udb', $exec[0]->argv[2]);
        self::assertStringContainsString('MYSQL_PWD', (string) $this->envSeen);
        self::assertStringNotContainsString('-pdb', $exec[0]->argv[2]);
        self::assertSame([], glob($dir.'/.fleet-snapshot-*') ?: []);
        self::assertSame($dir, $exec[0]->cwd);
        self::assertSame('-- sql', gzdecode((string) file_get_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z.sql.gz')));
        self::assertSame([], glob($dir.'/.fleet-snapshot-*') ?: []);
    }

    public function testAnEmptyExportIsAFailure(): void
    {
        $job = $this->running();
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(0), static function (array $argv): void {
            file_put_contents(substr($argv[3], 7), '');
        });

        try {
            $this->job()->execute($job, $this->log($job));
            self::fail('Expected a failure.');
        } catch (JobFailed $e) {
            self::assertSame('ddev export-db produced no dump.', $e->getMessage());
        }
        self::assertSame([], glob($this->assets.'/dumps/*') ?: []);
        self::assertSame([], glob($this->assets.'/dumps/.fleet-upload-*') ?: []);
    }

    public function testASymbolicLinkInTheWayIsRefused(): void
    {
        $job = $this->running();
        mkdir($this->assets, 0o750, true);
        $outside = sys_get_temp_dir().'/fleet-snap-'.bin2hex(random_bytes(4));
        mkdir($outside);
        symlink($outside, $this->assets.'/dumps');

        try {
            $this->job()->execute($job, $this->log($job));
            self::fail('Expected a failure.');
        } catch (JobFailed $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        } finally {
            @unlink($this->assets.'/dumps');
            @rmdir($outside);
        }
        self::assertSame([], $this->runner->calls());
    }

    public function testItIsTheJobTypeCalledSnapshot(): void
    {
        self::assertSame('snapshot', $this->job()->name());
        self::assertInstanceOf(SnapshotJob::class, self::getContainer()->get(SnapshotJob::class));
    }

    public function testNonDefaultCredentialsComeFromTheDdevDescriptionAndStayOutOfTheCommandLine(): void
    {
        $job = $this->running();
        $dir = $this->paths->instanceDir('blog--i1');
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(1, '', "access denied\n"));
        $this->runner->expect(['ddev', 'describe'], $this->describes('mariadb', 'drupal', "s3'cret pass", 'drupaldb', '3307'));
        $this->runner->expect(['ddev', 'exec'], new CommandResult(0), $this->dumpsA($dir));

        $this->job()->execute($job, $this->log($job));

        $exec = $this->runner->callsMatching(['ddev', 'exec']);
        self::assertStringContainsString('-udrupal', $exec[0]->argv[2]);
        self::assertStringContainsString('-P 3307', $exec[0]->argv[2]);
        self::assertStringEndsWith('drupaldb | gzip > '.substr($exec[0]->argv[2], (int) strrpos($exec[0]->argv[2], '.fleet-snapshot-')), $exec[0]->argv[2]);
        self::assertStringNotContainsString('cret', $exec[0]->argv[2]);
        self::assertSame("export MYSQL_PWD='s3'\\''cret pass'\n", $this->envSeen);
        self::assertStringNotContainsString('cret', (string) file_get_contents($this->paths->jobLog('1', 'blog--i1')));
        self::assertSame([], glob($dir.'/.fleet-snapshot-*') ?: []);
    }

    public function testAPostgresInstanceIsDumpedWithPgDump(): void
    {
        $job = $this->running();
        $dir = $this->paths->instanceDir('blog--i1');
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(1, '', "pg_dump: error\n"));
        $this->runner->expect(['ddev', 'describe'], $this->describes('postgres', 'pguser', 'pgpass', 'pgdb', '5432'));
        $this->runner->expect(['ddev', 'exec'], new CommandResult(0), $this->dumpsA($dir));

        $this->job()->execute($job, $this->log($job));

        $script = $this->runner->callsMatching(['ddev', 'exec'])[0]->argv[2];
        self::assertStringContainsString('pg_dump -h db -p 5432 -U pguser --no-owner pgdb', $script);
        self::assertStringNotContainsString('mariadb-dump', $script);
        self::assertStringNotContainsString('pgpass', $script);
        self::assertSame("export PGPASSWORD='pgpass'\n", $this->envSeen);
        self::assertSame('-- sql', gzdecode((string) file_get_contents($this->assets.'/dumps/default-blog--i1-20261020T090000Z.sql.gz')));
    }

    public function testAnUnusableDescriptionFallsBackToTheDefaultAccount(): void
    {
        $job = $this->running();
        $dir = $this->paths->instanceDir('blog--i1');
        $this->runner->expect(['ddev', 'export-db'], new CommandResult(1));
        $this->runner->expect(['ddev', 'describe'], $this->describes('mariadb', 'bad user; rm -rf /', 'x'));
        $this->runner->expect(['ddev', 'exec'], new CommandResult(0), $this->dumpsA($dir));

        $this->job()->execute($job, $this->log($job));

        $script = $this->runner->callsMatching(['ddev', 'exec'])[0]->argv[2];
        self::assertStringContainsString('-udb ', $script);
        self::assertStringNotContainsString('rm -rf', $script);
        self::assertSame("export MYSQL_PWD='db'\n", $this->envSeen);
    }
}
