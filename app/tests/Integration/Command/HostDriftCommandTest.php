<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\HostDriftCommand;
use App\Fleet\Host\Drift\DriftReport;
use App\Fleet\Host\Drift\DriftReportStore;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** FLE-261: fleet:host:drift runs the read-only helper, reports per role, keeps the last report and exits by the result. */
final class HostDriftCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        file_put_contents($this->tempHome.'/test-installed-version', "v1.7.0\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function enable(): void
    {
        file_put_contents($this->tempHome.'/test-apply-helper', "#!/bin/bash\nALLOWED_TARGETS='caddy authelia slices provision drift'\n");
        file_put_contents($this->tempHome.'/test-site.yml', "---\n");
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    /**
     * @param list<array<string, mixed>> $roles
     */
    private function answer(array $roles, int $exit = 0): void
    {
        $out = "PLAY noise\n".DriftReport::BEGIN."\n".json_encode(['schema' => 1, 'roles' => $roles])."\n".DriftReport::END."\n";
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'drift'], new CommandResult($exit, $out));
    }

    public function testNoDriftExitsZeroAndKeepsTheReport(): void
    {
        $this->enable();
        $this->answer([['role' => 'packages', 'changed' => [], 'drift' => [], 'failed' => []]]);

        $run = $this->cli('fleet:host:drift');

        self::assertSame(0, $run->getStatusCode());
        self::assertStringContainsString('packages', $run->getDisplay());
        self::assertStringContainsString('No drift.', $run->getDisplay());
        self::assertStringContainsString('firewall rules', $run->getDisplay());
        $last = self::getContainer()->get(DriftReportStore::class)->last();
        self::assertNotNull($last);
        self::assertSame('v1.7.0', $last->ref);
    }

    public function testDriftExitsOneAndJsonIsSchemaOne(): void
    {
        $this->enable();
        $this->answer([
            ['role' => 'caddy', 'changed' => [], 'drift' => ['the custom Caddy would be refreshed to 2.11.9 (installed 2.11.8)'], 'failed' => []],
            ['role' => 'units', 'changed' => [['task' => 'Install the unit', 'diff' => '+x']], 'drift' => [], 'failed' => []],
        ], 0);

        $run = $this->cli('fleet:host:drift', ['--json' => true]);
        $json = $this->json($run);

        self::assertSame(HostDriftCommand::EXIT_DRIFT, $run->getStatusCode());
        self::assertSame(1, $json['schema']);
        self::assertTrue($json['drift']);
        self::assertFalse($json['failed']);
        self::assertSame(['changed', 'changed'], array_column($json['roles'], 'status'));
        self::assertSame('v1.7.0', $json['ref']);
    }

    public function testAFailedTaskExitsTwoEvenWhenThePlaybookExitedNonZero(): void
    {
        $this->enable();
        $this->answer([['role' => 'docker', 'changed' => [], 'drift' => [], 'failed' => [['task' => 'Read the pool', 'msg' => 'boom']]]], 2);

        $run = $this->cli('fleet:host:drift');

        self::assertSame(HostDriftCommand::EXIT_FAILED, $run->getStatusCode());
        self::assertStringContainsString('FAILED: Read the pool: boom', $run->getDisplay());
    }

    public function testNoReportMeansExitThreeAndNothingStored(): void
    {
        $this->enable();
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'drift'], new CommandResult(66, '', 'apply: the installed playbook is missing'));

        $run = $this->cli('fleet:host:drift');

        self::assertSame(HostDriftCommand::EXIT_NO_REPORT, $run->getStatusCode());
        self::assertStringContainsString('no drift report', strtolower($run->getErrorOutput()));
        self::assertNull(self::getContainer()->get(DriftReportStore::class)->last());
    }

    public function testAHostWithoutTheHelperTargetRefusesBeforeCallingSudo(): void
    {
        $run = $this->cli('fleet:host:drift');

        self::assertSame(HostDriftCommand::EXIT_NO_REPORT, $run->getStatusCode());
        self::assertStringContainsString('does not know "apply drift"', $run->getErrorOutput());
        self::assertSame([], $this->runner()->calls());
    }

    public function testAHostNotInstalledByAnsibleGetsAWarningInTheTextAndTheJson(): void
    {
        $this->enable();
        $this->answer([['role' => 'units', 'changed' => [['task' => 'Record the install', 'diff' => '+ansible']], 'drift' => [], 'failed' => []]]);

        $text = $this->cli('fleet:host:drift');
        self::assertStringContainsString('WARNING: This host was not installed with the Ansible engine', $text->getDisplay());

        $this->answer([['role' => 'units', 'changed' => [], 'drift' => [], 'failed' => []]]);
        file_put_contents($this->tempHome.'/test-installed-engine', "ansible\n");
        $json = $this->json($this->cli('fleet:host:drift', ['--json' => true]));
        self::assertNull($json['warning']);
        self::assertSame('ansible', $json['engine']);
    }
}
