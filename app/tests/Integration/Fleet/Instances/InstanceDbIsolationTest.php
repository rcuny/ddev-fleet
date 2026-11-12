<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\InstanceConfigWriter;
use App\Fleet\Instances\InstanceDbIsolationWriter;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\RestartJob;
use App\Fleet\Jobs\Type\StartJob;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-297 / FLE-307: after each start the non-web containers are disconnected from ddev_default (no compose override for a db); an instance without one pins "db" to loopback in every service; the
 * live networks are checked after each start path; a db is always detached, whatever its labels.
 */
final class InstanceDbIsolationTest extends InstanceJobTestCase
{
    private const WITH_DB = "services:\n  db:\n    image: db\n  web:\n    image: web\n";
    private const WITHOUT_DB = "services:\n  web:\n    image: web\n  mailpit:\n    image: m\n";

    /** @return array{0: \App\Domain\Instance\Instance, 1: string} */
    private function prepare(string $composeConfig, InstanceState $state = InstanceState::Stopped): array
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, $composeConfig));
        $instance = $this->instance('main', 'develop', $state);
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.ddev', 0o750, true);

        return [$instance, $dir];
    }

    public function testAnInstanceWithADbGetsNoComposeFileAndAStaleOneIsRemoved(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        file_put_contents($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH, "# stale FLE-297 override\n");
        $lines = [];
        $path = new InstanceDbIsolationWriter($this->ddev)->write($dir, $instance->id(), static function (string $l) use (&$lines): void { $lines[] = $l; });

        self::assertNull($path);
        self::assertFileDoesNotExist($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH);
        self::assertStringContainsString('removed', implode("\n", $lines));
    }

    public function testAnInstanceWithoutDbPinsTheNamesInEveryService(): void
    {
        [$instance, $dir] = $this->prepare(self::WITHOUT_DB);
        new InstanceDbIsolationWriter($this->ddev)->write($dir, $instance->id(), $this->logFor($this->job('deploy', $instance))->line(...));
        $parsed = Yaml::parse((string) file_get_contents($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH));
        $pins = ['db:127.0.0.1', 'ddev-'.$instance->id().'-db:127.0.0.1'];

        self::assertSame($pins, $parsed['services']['web']['extra_hosts']);
        self::assertSame($pins, $parsed['services']['mailpit']['extra_hosts']);
        self::assertArrayNotHasKey('db', $parsed['services']);
    }

    public function testTheFileSortsAfterAProjectOverrideAndTheOldNameIsRemoved(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        file_put_contents($dir.'/'.InstanceDbIsolationWriter::LEGACY_PATH, 'old');
        $name = new InstanceDbIsolationWriter($this->ddev)->write($dir, 'x--y', $this->logFor($this->job('deploy', $instance))->line(...)) ?? InstanceDbIsolationWriter::RELATIVE_PATH;

        $files = ['docker-compose.override.yaml', 'docker-compose.zz.yaml', basename($name)];
        $sorted = $files;
        sort($sorted);
        self::assertSame(basename($name), end($sorted));
        self::assertFileDoesNotExist($dir.'/'.InstanceDbIsolationWriter::LEGACY_PATH);
    }

    private function ps(string $project, string ...$rows): void
    {
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, implode("\n", $rows)."\n"));
    }

    private function psRow(string $project, string $service, string $labels = ''): string
    {
        return 'ddev-'.$project.'-'.$service.'|'.$service.'|com.ddev.site-name='.$project.$labels;
    }

    private function auditCount(): int
    {
        return \count($this->em->getRepository(\App\Domain\Audit\AuditEntry::class)->findBy(['action' => 'isolation.db-disconnect']));
    }

    private function converger(): InstanceDbIsolationWriter
    {
        return new InstanceDbIsolationWriter($this->ddev, new \App\Fleet\Audit\AuditLog(new \App\Domain\Audit\AuditEntryRepository($this->em), $this->clock));
    }

    public function testConvergeLeavesAContainerThatIsOffTheSharedNetwork(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'), $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"'.$id.'_default":{}}'));

        self::assertSame([], $this->converger()->converge($id, static function (string $l): void {}));
        self::assertSame([], $this->runner->callsMatching(['docker', 'network']));
        self::assertSame(0, $this->auditCount());
    }

    public function testAttachedAfterStartIsDisconnectedAndSucceeds(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'), $this->psRow($id, 'db'), $this->psRow($id, 'mailpit'), $this->psRow($id, 'typesense', ',traefik.enable=true'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '["PATH=/bin"]')); // mailpit env: no HTTP_EXPOSE
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{},"x_default":{}}')); // db before
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}')); // db after
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}')); // mailpit, already off
        $lines = [];

        $cut = $this->converger()->converge($id, static function (string $l) use (&$lines): void { $lines[] = $l; });

        self::assertSame(['ddev-'.$id.'-db'], $cut);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'disconnect', 'ddev_default', 'ddev-'.$id.'-db']));
        self::assertStringNotContainsString('SECURITY', implode("\n", $lines));
        self::assertStringContainsString('disconnected', implode("\n", $lines));
        self::assertSame(1, $this->auditCount());
        // web and the label-routed typesense are never inspected for networks; mailpit's env is read once
        self::assertCount(4, $this->runner->callsMatching(['docker', 'inspect']));
    }

    public function testADbWithTraefikLabelsIsStillDetached(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db', ',traefik.enable=true,traefik.http.routers.x.rule=Host(`a`)'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}'));

        self::assertSame(['ddev-'.$id.'-db'], $this->converger()->converge($id, static function (string $l): void {}));
    }

    public function testAServiceWithHttpExposeEnvStaysAttachedAndADbWithItDoesNot(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'), $this->psRow($id, 'typesense'), $this->psRow($id, 'sidecar'));
        $this->runner->expect(['docker', 'inspect', '--format', '{{json .Config.Env}}'], new CommandResult(0, '["A=b","HTTP_EXPOSE=9108:8108"]')); // typesense: routed
        $this->runner->expect(['docker', 'inspect', '--format', '{{json .Config.Env}}'], new CommandResult(0, '["A=b"]')); // sidecar: not exposed
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}')); // db before
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}')); // sidecar before
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}')); // db after
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}')); // sidecar after

        $cut = $this->converger()->converge($id, static function (string $l): void {});

        self::assertSame(['ddev-'.$id.'-db', 'ddev-'.$id.'-sidecar'], $cut);
        self::assertSame([], $this->runner->callsMatching(['docker', 'network', 'disconnect', 'ddev_default', 'ddev-'.$id.'-typesense']));
    }

    public function testADbWithHttpExposeEnvIsStillDetached(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}'));

        self::assertSame(['ddev-'.$id.'-db'], $this->converger()->converge($id, static function (string $l): void {}));
        self::assertSame([], $this->runner->callsMatching(['docker', 'inspect', '--format', '{{json .Config.Env}}']), 'the db env is never consulted');
    }

    public function testALabelValueContainingTraefikDoesNotExemptAService(): void
    {
        self::assertFalse(\App\Fleet\Ddev\Ddev::hasRouterLabel('a=b,note=see traefik.enable,c=d'));
        self::assertTrue(\App\Fleet\Ddev\Ddev::hasRouterLabel('a=b,traefik.http.routers.t.rule=Host(`x`)'));
        self::assertTrue(\App\Fleet\Ddev\Ddev::hasRouterLabel('traefik.enable=true'));
    }

    public function testAFailedDisconnectFailsTheJob(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(1, '', 'denied'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disconnecting it failed');
        $this->converger()->converge($id, static function (string $l): void {});
    }

    public function testStillAttachedAfterTheDisconnectFails(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still attached');
        $this->converger()->converge($id, static function (string $l): void {});
    }

    public function testExposedOnlyReportsAndChangesNothing(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));

        self::assertSame(['ddev-'.$id.'-db'], new InstanceDbIsolationWriter($this->ddev)->exposed($id));
        self::assertSame([], $this->runner->callsMatching(['docker', 'network']));
    }

    private function startJob(): StartJob
    {
        return new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate(true));
    }

    public function testTheStartJobWritesBeforeStartingAndConvergesAfter(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $job = $this->job('start', $instance);

        $this->startJob()->execute($job, $this->logFor($job));

        $names = $this->commandNames();
        self::assertLessThan(array_search('ddev start', $names, true), array_search('ddev debug', $names, true));
        self::assertGreaterThan(array_search('ddev start', $names, true), array_search('docker ps', $names, true));
    }

    public function testTheStartJobFailsWhenTheDisconnectFails(): void
    {
        [$instance] = $this->prepare(self::WITH_DB);
        $this->ps($instance->id(), $this->psRow($instance->id(), 'db'));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(1));
        $job = $this->job('start', $instance);

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('database isolation is not in effect');
        $this->startJob()->execute($job, $this->logFor($job));
    }

    public function testTheRestartJobWritesTheFileAndVerifies(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB, InstanceState::Running);
        $restart = new RestartJob(
            $this->instances,
            new InstanceConfigWriter($this->instanceHostnames, new \App\Fleet\Instances\GitIdentityResolver(new \App\Fleet\Instances\DomainGitIdentity($this->instanceHostnames)), $this->secretStore, $this->masker, new \App\Fleet\Typesense\TypesenseEnvironment(new \App\Fleet\Typesense\TypesenseKeys($this->secretStore), new \App\Domain\Port\NamedPortRepository($this->em), $this->instanceHostnames, $this->masker), null),
            $this->ddev,
            $this->paths,
            $this->feed,
            $this->preStartGate(true),
        );
        $job = $this->job('restart', $instance);

        $restart->execute($job, $this->logFor($job));

        $names = $this->commandNames();
        self::assertContains('docker ps', $names);
        self::assertGreaterThan(array_search('ddev restart', $names, true), array_search('docker ps', $names, true));
    }

    public function testTheRecordingRunnerAnswersTheProbesWhenUnscripted(): void
    {
        // Dev and the Playwright suite run on the recording runner: a deploy must get past the isolation steps there.
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::WITH_DB));
        $writer = new InstanceDbIsolationWriter($this->ddev);
        $writer->write($dir, $instance->id(), static function (string $l): void {});
        self::assertSame([], $writer->converge($instance->id(), static function (string $l): void {}));
    }

    public function testRootIsLockedOverTheDbContainersSocketAndNothingElseChanges(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'), $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'exec'], new CommandResult(0));
        $lines = [];

        self::assertTrue($this->converger()->lockRoot($id, static function (string $l) use (&$lines): void { $lines[] = $l; }));

        $calls = $this->runner->callsMatching(['docker', 'exec', 'ddev-'.$id.'-db', 'mysql']);
        self::assertCount(1, $calls);
        self::assertContains(InstanceDbIsolationWriter::LOCK_ROOT_SQL, $calls[0]->argv);
        self::assertStringContainsString('locked', implode("\n", $lines));
        // db/db and root@localhost are never touched, and no password is involved
        self::assertStringNotContainsString("'db'", InstanceDbIsolationWriter::LOCK_ROOT_SQL);
        self::assertStringNotContainsString('IDENTIFIED', InstanceDbIsolationWriter::LOCK_ROOT_SQL);
    }

    public function testNoDbContainerMeansNothingToLock(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'), $this->psRow($id, 'mailpit'));

        self::assertFalse($this->converger()->lockRoot($id, static function (string $l): void {}));
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec']));
    }

    public function testAServerThatRefusesTheLockOnlyWarns(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'exec'], new CommandResult(1, '', 'ERROR 1064 (42000): You have an error in your SQL syntax'));
        $lines = [];

        self::assertFalse($this->converger()->lockRoot($id, static function (string $l) use (&$lines): void { $lines[] = $l; }));
        self::assertStringContainsString('WARNING', implode("\n", $lines));
    }

    public function testTheSocketUserOfUid999IsCreatedWithAllPrivileges(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'), $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'exec'], new CommandResult(0));

        self::assertTrue($this->converger()->ensureSocketUser($id, static function (string $l): void {}));

        $calls = $this->runner->callsMatching(['docker', 'exec', 'ddev-'.$id.'-db', 'mysql']);
        self::assertCount(1, $calls);
        self::assertContains(InstanceDbIsolationWriter::SOCKET_USER_SQL_MARIADB, $calls[0]->argv);
        self::assertStringContainsString("'mysql'@'localhost'", InstanceDbIsolationWriter::SOCKET_USER_SQL_MARIADB);
        self::assertStringContainsString('unix_socket', InstanceDbIsolationWriter::SOCKET_USER_SQL_MARIADB);
        self::assertStringContainsString('ON *.*', InstanceDbIsolationWriter::SOCKET_USER_SQL_MARIADB);
        self::assertStringNotContainsString('IDENTIFIED BY', InstanceDbIsolationWriter::SOCKET_USER_SQL_MARIADB);
    }

    public function testTheSocketUserFallsBackToMysqlSyntaxAndOnlyWarnsWhenBothFail(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'db'));
        $this->runner->expect(['docker', 'exec'], new CommandResult(1, '', 'ERROR 1064 (42000)'));
        $this->runner->expect(['docker', 'exec'], new CommandResult(1, '', 'ERROR 1064 (42000)'));
        $lines = [];

        self::assertFalse($this->converger()->ensureSocketUser($id, static function (string $l) use (&$lines): void { $lines[] = $l; }));

        self::assertCount(2, $this->runner->callsMatching(['docker', 'exec']));
        self::assertContains(InstanceDbIsolationWriter::SOCKET_USER_SQL_MYSQL, $this->runner->callsMatching(['docker', 'exec'])[1]->argv);
        self::assertStringContainsString('WARNING', implode("\n", $lines));
    }

    public function testNoDbContainerMeansNoSocketUser(): void
    {
        $id = 'blog--main';
        $this->ps($id, $this->psRow($id, 'web'));

        self::assertFalse($this->converger()->ensureSocketUser($id, static function (string $l): void {}));
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec']));
    }
}
