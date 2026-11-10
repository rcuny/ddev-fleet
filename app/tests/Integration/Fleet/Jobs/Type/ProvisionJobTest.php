<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Fleet\Host\Provision\HostVarsReader;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\ProvisionJob;
use App\Fleet\Shell\CommandResult;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\HostVarsRenderer;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\SystemRenderer;
use App\Tests\Support\InstanceJobTestCase;

final class ProvisionJobTest extends InstanceJobTestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function provision(array $params): Job
    {
        $job = new Job('provision', null, null, JobTrigger::Ui, 'admin', $params, null, false, null, $this->clock->now());
        $this->em->persist($job);
        $this->em->flush();
        $system = self::getContainer()->get(SystemRenderer::class);
        self::assertInstanceOf(SystemRenderer::class, $system);
        new ProvisionJob(new HostSettingsRepository($this->em), new HostVarsReader($this->paths->home().'/none.yml'), new HostVarsRenderer(), $system, $this->runner)->execute($job, $this->logFor($job));

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return ['enabled' => true, 'sshAllowUsers' => ['admin'], 'trustedIps' => ['203.0.113.10'], 'graceMinutes' => 12, 'hardenTmp' => false, 'invokingUser' => 'admin'];
    }

    public function testStagesTheRenderedAnswersCallsOnlyTheFixedHelperAndDiscardsTheStaging(): void
    {
        $staged = null;
        $file = $this->paths->stagingFile('provision', 'host-vars.yml');
        $this->runner->expect(['sudo', '-n', '--', PrivilegedApply::HELPER], new CommandResult(0, "PLAY RECAP\n"), static function () use (&$staged, $file): void {
            $staged = (string) file_get_contents($file);
        });

        $job = $this->provision($this->params());

        self::assertSame(['sudo -n -- '.PrivilegedApply::HELPER.' provision'], array_map(static fn (array $argv): string => implode(' ', $argv), $this->argvs()));
        self::assertNotNull($staged);
        self::assertStringContainsString("fleet_hardening_enabled: true\n", $staged);
        self::assertStringContainsString("fleet_hardening_firewall_grace_minutes: 12\n", $staged);
        self::assertStringContainsString("fleet_hardening_invoking_user: \"admin\"\n", $staged);
        self::assertFileDoesNotExist($file);
        self::assertStringContainsString('sudo fleet-firewall-confirm', $this->logText($job));
    }

    public function testAFailedRunFailsTheJobWithItsExitCode(): void
    {
        $this->runner->expect(['sudo', '-n', '--'], new CommandResult(1, '', 'apply: provision failed (exit 2)'));

        try {
            $this->provision($this->params());
            self::fail('expected a JobFailed');
        } catch (JobFailed $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('failed (exit 1)', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->paths->stagingFile('provision', 'host-vars.yml'));
    }

    public function testInvalidParamsNeverReachTheHelper(): void
    {
        try {
            $this->provision(['enabled' => true, 'sshAllowUsers' => ['admin;id'], 'invokingUser' => 'admin'] + $this->params());
            self::fail('expected a JobFailed');
        } catch (JobFailed) {
        }
        self::assertSame([], $this->runner->callsMatching(['sudo']));
        self::assertSame('provision', ApplyTarget::Provision->value);
    }
}
