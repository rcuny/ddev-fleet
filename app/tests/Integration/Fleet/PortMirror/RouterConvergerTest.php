<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\StepPhase;
use App\Fleet\PortMirror\PortMirrorConvergeStep;
use App\Fleet\PortMirror\PortMirrorFailed;
use App\Fleet\PortMirror\PortMirrorSyncStep;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\PortMirrorTestCase;

final class RouterConvergerTest extends PortMirrorTestCase
{
    private const array BASE = ['http-80', 'http-8080', 'https-8443'];
    private const array WITH_MIRROR = ['http-80', 'http-8080', 'https-8443', 'fleet-mirror-443', 'fleet-mirror-9108'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue(new NamedPort('typesense', 9108, 8108));
        $this->globalConfig("router_https_port: 8443\n");
    }

    /**
     * @return list<string> "docker restart", "docker inspect" and "ddev start" in call order
     */
    private function commands(): array
    {
        return array_map(static fn (array $argv): string => $argv[0].' '.$argv[1], $this->argvs());
    }

    private function healthy(): void
    {
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, "healthy\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, "healthy\n"));
    }

    public function testTheRouterIsLeftAloneWhenItAlreadyMatches(): void
    {
        $this->mirror->sync();
        $this->live->script(self::WITH_MIRROR);

        self::assertSame('', $this->converger->converge('/tmp/instance', static function (): void {
        }));

        self::assertSame([], $this->argvs());
    }

    public function testTheRouterIsRestartedOnlyOnADifference(): void
    {
        $this->mirror->sync();
        $this->healthy();
        $this->live->script(self::BASE, self::WITH_MIRROR);

        $message = $this->converger->converge('/tmp/instance', static function (): void {
        });

        self::assertSame('restarted ddev-router: port mirror now 443, 9108', $message);
        self::assertSame(['docker restart', 'docker inspect'], $this->commands());
        self::assertSame(['docker', 'restart', 'ddev-router'], $this->argvs()[0]);
        self::assertSame(['docker', 'inspect', '-f', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', 'ddev-router'], $this->argvs()[1]);
        self::assertFileDoesNotExist($this->traefik->markerFile());
    }

    public function testASwitchedOffMirrorStillLiveIsRemovedFromTheRouter(): void
    {
        $this->mirror->sync();
        $this->switchMirror(false);
        $this->mirror->sync();
        $this->healthy();
        $this->live->script(self::WITH_MIRROR, self::BASE);

        $message = $this->converger->converge('/tmp/instance', static function (): void {
        });

        self::assertSame('restarted ddev-router: port mirror now none', $message);
        self::assertSame(['docker restart', 'docker inspect'], $this->commands());
    }

    public function testAForeignDeclaredMirrorNameDoesNotCauseARestart(): void
    {
        $this->mirror->sync();
        $this->foreign('static_config.spike.yaml', "entryPoints:\n  fleet-mirror-9999:\n    address: \":9999\"\n");
        $this->live->script([...self::WITH_MIRROR, 'fleet-mirror-9999']);

        self::assertSame('', $this->converger->converge('/tmp/instance', static function (): void {
        }));
        self::assertSame([], $this->argvs());
    }

    public function testAnUnreachableApiRestartsNothing(): void
    {
        $this->mirror->sync();
        $this->live->script(null);

        self::assertSame('router API unreachable; port mirror not verified', $this->converger->converge('/tmp/instance', static function (): void {
        }));
        self::assertSame([], $this->argvs());
    }

    public function testStaleOrMissingFilesRestartNothing(): void
    {
        $this->live->script(self::BASE);

        self::assertSame('port mirror files missing or stale; router not restarted', $this->converger->converge('/tmp/instance', static function (): void {
        }));
        self::assertSame([], $this->argvs());
    }

    public function testNoTraefikDirectoryMeansNothingToDo(): void
    {
        rmdir($this->traefik->dir().'/custom-global-config') ?: null;
        foreach (glob($this->traefik->dir().'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->traefik->dir());

        self::assertSame('', $this->converger->converge('/tmp/instance', static function (): void {
        }));
        self::assertSame(0, $this->live->reads);
    }

    public function testAMatchingMarkerStopsTheRestart(): void
    {
        $this->mirror->sync();
        $this->files->writeMarker([443, 9108]);
        $this->live->script(self::BASE);

        $message = $this->converger->converge('/tmp/instance', static function (): void {
        });

        self::assertStringStartsWith('port mirror disabled after a failed apply', $message);
        self::assertSame([], $this->argvs());
    }

    public function testAFailureRemovesTheFilesWritesTheMarkerAndRestartsAgain(): void
    {
        $this->mirror->sync();
        $this->healthy();
        // The router never shows the mirror.
        $this->live->script(self::BASE);

        try {
            $this->converger->converge('/tmp/instance', static function (): void {
            });
            self::fail('expected PortMirrorFailed');
        } catch (PortMirrorFailed $e) {
            self::assertStringContainsString('still differ from the wanted mirror', $e->getMessage());
            self::assertStringContainsString('runs without the mirror', $e->getMessage());
        }

        self::assertSame(['docker restart', 'docker inspect', 'ddev start', 'docker restart'], $this->commands());
        self::assertSame('/tmp/instance', $this->runner->calls()[2]->cwd);
        self::assertFileDoesNotExist($this->traefik->staticFile());
        self::assertFileDoesNotExist($this->traefik->dynamicFile());
        self::assertSame("443,9108\n", $this->read($this->traefik->markerFile()));

        // The marker now keeps sync and converge quiet.
        self::assertFalse($this->mirror->sync()->changed);
        self::assertFileDoesNotExist($this->traefik->staticFile());
    }

    public function testADockerRestartThatFailsIsAFailureToo(): void
    {
        $this->mirror->sync();
        $this->runner->expect(['docker', 'restart'], new CommandResult(1, '', 'no such container'));
        $this->live->script(self::BASE);

        $this->expectException(PortMirrorFailed::class);
        $this->expectExceptionMessage('docker restart ddev-router failed');
        try {
            $this->converger->converge('/tmp/instance', static function (): void {
            });
        } finally {
            self::assertSame(['docker restart', 'ddev start', 'docker restart'], $this->commands());
            self::assertFileExists($this->traefik->markerFile());
        }
    }

    public function testAnUnhealthyRouterIsAFailure(): void
    {
        $this->mirror->sync();
        $this->runner->expect(['docker', 'restart'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, "unhealthy\n"));
        $this->live->script(self::BASE);

        $this->expectException(PortMirrorFailed::class);
        $this->expectExceptionMessage('was not healthy within');
        $this->converger->converge('/tmp/instance', static function (): void {
        });
    }

    public function testTheSyncStepRunsBeforeStartAndTheConvergeStepNeverFailsTheDeploy(): void
    {
        $sync = new PortMirrorSyncStep($this->mirror);
        $converge = new PortMirrorConvergeStep($this->converger);
        self::assertSame(StepPhase::BeforeStart, $sync->phase());
        self::assertSame(StepPhase::AfterStart, $converge->phase());

        $instance = $this->instance();
        $dir = $this->checkout($instance);
        $job = $this->job('deploy', $instance);
        $context = new DeployContext($instance, $this->project, $dir, [], $this->logFor($job), $this->secretRenderer);

        $sync->run($context);
        self::assertFileExists($this->traefik->staticFile());

        $this->healthy();
        $this->live->script(self::BASE);
        $converge->run($context);

        $log = $this->logText($job);
        self::assertStringContainsString('port mirror files updated (public ports 443, 9108)', $log);
        self::assertStringContainsString('WARNING: The in-network port mirror could not be applied', $log);
        self::assertFileExists($this->traefik->markerFile());
        self::assertSame($dir, $this->runner->calls()[2]->cwd, 'ddev start runs in the instance directory');
    }

    public function testTheConvergeStepSaysNothingWhenThereIsNothingToDo(): void
    {
        $this->mirror->sync();
        $this->live->script(self::WITH_MIRROR);
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);

        new PortMirrorConvergeStep($this->converger)->run(new DeployContext($instance, $this->project, $this->checkout($instance), [], $this->logFor($job), $this->secretRenderer));

        self::assertStringNotContainsString('port mirror', $this->logText($job));
        self::assertSame([], $this->argvs());
    }
}
