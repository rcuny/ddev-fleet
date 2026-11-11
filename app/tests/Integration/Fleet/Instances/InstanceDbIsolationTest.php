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
 * FLE-297: the db container leaves ddev_default; an instance without one pins "db" to loopback in every service; the
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

    private function write(string $composeConfig): string
    {
        [$instance, $dir] = $this->prepare($composeConfig);
        new InstanceDbIsolationWriter($this->ddev)->write($dir, $instance->id(), $this->logFor($this->job('deploy', $instance))->line(...));

        return (string) file_get_contents($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH);
    }

    public function testTheDbServiceKeepsOnlyTheProjectNetwork(): void
    {
        $text = $this->write(self::WITH_DB);

        self::assertStringContainsString('!override', $text);
        self::assertStringNotContainsString('ddev_default', $text);
        self::assertStringNotContainsString('#ddev-generated', $text);
        self::assertStringNotContainsString('extra_hosts', $text);
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
        $name = new InstanceDbIsolationWriter($this->ddev)->write($dir, $instance->id(), $this->logFor($this->job('deploy', $instance))->line(...));

        $files = ['docker-compose.override.yaml', 'docker-compose.zz.yaml', basename($name)];
        $sorted = $files;
        sort($sorted);
        self::assertSame(basename($name), end($sorted));
        self::assertFileDoesNotExist($dir.'/'.InstanceDbIsolationWriter::LEGACY_PATH);
    }

    public function testIsIsolatedChecksTheContentNotOnlyTheFile(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        self::assertFalse(InstanceDbIsolationWriter::isIsolated($dir));
        file_put_contents($dir.'/'.InstanceDbIsolationWriter::RELATIVE_PATH, '');
        self::assertFalse(InstanceDbIsolationWriter::isIsolated($dir));
        new InstanceDbIsolationWriter($this->ddev)->write($dir, $instance->id(), $this->logFor($this->job('deploy', $instance))->line(...));
        self::assertTrue(InstanceDbIsolationWriter::isIsolated($dir));
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

    public function testVerifyPassesWhenDbIsOffTheSharedNetwork(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $writer = new InstanceDbIsolationWriter($this->ddev);
        $writer->write($dir, $instance->id(), static function (string $l): void {});
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"'.$instance->id().'_default":{}}'));

        $writer->verify($dir, $instance->id(), static function (string $l): void {});

        $inspect = $this->runner->callsMatching(['docker', 'inspect']);
        $argv = $inspect[0]->argv;
        self::assertSame('ddev-'.$instance->id().'-db', end($argv));
        self::assertSame([], $this->runner->callsMatching(['docker', 'network']));
    }

    public function testVerifyFailsClosedAndDisconnectsWhenDbIsStillOnTheSharedNetwork(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $writer = new InstanceDbIsolationWriter($this->ddev);
        $writer->write($dir, $instance->id(), static function (string $l): void {});
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{},"x_default":{}}'));

        try {
            $writer->verify($dir, $instance->id(), static function (string $l): void {});
            self::fail('expected a failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('attached to ddev_default', $e->getMessage());
            self::assertStringContainsString('was disconnected', $e->getMessage());
        }
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'disconnect', 'ddev_default']));
    }

    public function testVerifyFailsWhenTheContainerCannotBeInspected(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $writer = new InstanceDbIsolationWriter($this->ddev);
        $writer->write($dir, $instance->id(), static function (string $l): void {});
        $this->runner->expect(['docker', 'inspect'], new CommandResult(1, '', 'no such object'));

        $this->expectException(\RuntimeException::class);
        $writer->verify($dir, $instance->id(), static function (string $l): void {});
    }

    private function startJob(): StartJob
    {
        return new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate(true));
    }

    public function testTheStartJobWritesTheFileBeforeStartingAndVerifiesAfter(): void
    {
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $job = $this->job('start', $instance);

        $this->startJob()->execute($job, $this->logFor($job));

        self::assertTrue(InstanceDbIsolationWriter::isIsolated($dir));
        $names = $this->commandNames();
        self::assertLessThan(array_search('ddev start', $names, true), array_search('ddev debug', $names, true));
        self::assertGreaterThan(array_search('ddev start', $names, true), array_search('docker inspect', $names, true));
    }

    public function testTheStartJobFailsWhenTheDbIsStillExposed(): void
    {
        [$instance] = $this->prepare(self::WITH_DB);
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
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

        self::assertTrue(InstanceDbIsolationWriter::isIsolated($dir));
        $names = $this->commandNames();
        self::assertContains('docker inspect', $names);
        self::assertGreaterThan(array_search('ddev restart', $names, true), array_search('docker inspect', $names, true));
    }

    public function testTheRecordingRunnerAnswersTheProbesWhenUnscripted(): void
    {
        // Dev and the Playwright suite run on the recording runner: a deploy must get past the isolation steps there.
        [$instance, $dir] = $this->prepare(self::WITH_DB);
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::WITH_DB));
        $writer = new InstanceDbIsolationWriter($this->ddev);
        $writer->write($dir, $instance->id(), static function (string $l): void {});
        $writer->verify($dir, $instance->id(), static function (string $l): void {});

        self::assertTrue(InstanceDbIsolationWriter::isIsolated($dir));
    }
}
