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
 * live networks are checked after each start path; old DDEV or docker compose is refused.
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

    public function testAnOldDdevOrComposeIsRefused(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $this->runner->expect(['ddev', '--version'], new CommandResult(0, "ddev version v1.23.5\n"));
        $writer = new InstanceDbIsolationWriter($this->ddev);
        try {
            $writer->write($dir, $instance->id(), static function (string $l): void {});
            self::fail('expected a refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('DDEV >= '.InstanceDbIsolationWriter::MIN_DDEV, $e->getMessage());
        }
        self::assertFileDoesNotExist($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH);

        $this->runner->expect(['docker', 'compose'], new CommandResult(0, "2.20.0\n"));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('docker compose >=');
        $writer->assertSupported();
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
        // web and the routed typesense are never inspected
        self::assertCount(3, $this->runner->callsMatching(['docker', 'inspect']));
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
}
