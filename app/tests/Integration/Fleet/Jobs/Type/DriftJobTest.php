<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Fleet\Host\Drift\DriftCheck;
use App\Fleet\Host\Drift\DriftReport;
use App\Fleet\Host\Drift\DriftReportStore;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\DriftJob;
use App\Fleet\Shell\CommandResult;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\Upgrade\InstalledVersion;
use App\Tests\Support\InstanceJobTestCase;

/** FLE-261: the drift check as a job: a fixed read-only helper call, a summary in the log (never the raw output), the report stored. */
final class DriftJobTest extends InstanceJobTestCase
{
    private function runJob(): Job
    {
        $home = $this->paths->home();
        file_put_contents($home.'/helper', "#!/bin/bash\nALLOWED_TARGETS='caddy authelia slices provision drift'\n");
        file_put_contents($home.'/site.yml', "---\n");
        file_put_contents($home.'/version', "v1.7.0\n");
        $store = new DriftReportStore($home.'/drift-report.json');
        $check = new DriftCheck($this->runner, new PrivilegedApply($this->runner, $home.'/helper'), $store, new InstalledVersion($this->runner, $home.'/version', $home), $this->clock, $home.'/site.yml');
        $job = new Job('drift', null, null, JobTrigger::Ui, 'admin', [], null, false, null, $this->clock->now());
        $this->em->persist($job);
        $this->em->flush();
        new DriftJob($check)->execute($job, $this->logFor($job));

        return $job;
    }

    /**
     * @param list<array<string, mixed>> $roles
     */
    private function answer(array $roles, int $exit = 0): void
    {
        $out = DriftReport::BEGIN."\n".json_encode(['schema' => 1, 'roles' => $roles])."\n".DriftReport::END."\n";
        $this->runner->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'drift'], new CommandResult($exit, $out));
    }

    public function testDriftIsAResultNotAFailureAndTheLogHoldsOnlyASummary(): void
    {
        $this->answer([['role' => 'units', 'changed' => [['task' => 'Install the unit', 'diff' => '+token: sekrit']], 'drift' => [], 'failed' => []]]);

        $job = $this->runJob();

        self::assertSame(['sudo -n -- '.PrivilegedApply::HELPER.' drift'], array_map(static fn (array $argv): string => implode(' ', $argv), $this->argvs()));
        $log = $this->logText($job);
        self::assertStringContainsString('units: changed (1 changed, 0 drift message(s), 0 failed)', $log);
        self::assertStringContainsString('Drift found.', $log);
        self::assertStringNotContainsString('sekrit', $log);
        self::assertStringNotContainsString('Install the unit', $log);
        $stored = new DriftReportStore($this->paths->home().'/drift-report.json')->last();
        self::assertNotNull($stored);
        self::assertTrue($stored->hasDrift());
        self::assertStringNotContainsString('sekrit', (string) file_get_contents($this->paths->home().'/drift-report.json'));
    }

    public function testAFailedTaskFailsTheJobButTheReportIsKept(): void
    {
        $this->answer([['role' => 'docker', 'changed' => [], 'drift' => [], 'failed' => [['task' => 'Read the pool', 'msg' => 'boom']]]], 2);

        try {
            $this->runJob();
            self::fail('expected a JobFailed');
        } catch (JobFailed $e) {
            self::assertStringContainsString('could not evaluate', $e->getMessage());
        }
        self::assertTrue(new DriftReportStore($this->paths->home().'/drift-report.json')->last()?->hasFailures());
    }

    public function testNoReportFailsTheJobWithTheHelpersMessage(): void
    {
        $this->runner->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'drift'], new CommandResult(66, '', 'apply: the installed playbook is missing'));

        try {
            $this->runJob();
            self::fail('expected a JobFailed');
        } catch (JobFailed $e) {
            self::assertStringContainsString('no drift report', $e->getMessage());
            self::assertStringContainsString('playbook is missing', $e->getMessage());
        }
    }
}
