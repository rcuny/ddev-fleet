<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\OutputTail;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\Upgrade\InstalledEngine;
use App\Fleet\Upgrade\InstalledVersion;
use Psr\Clock\ClockInterface;

/**
 * Runs the drift check (FLE-261): the root helper `apply drift` runs the installed tag's playbook with --check --diff,
 * which changes nothing, and prints the report. The caller (the console command, the job) chooses neither the playbook
 * nor an argument. The report is masked, stored as the last one and returned.
 */
final readonly class DriftCheck
{
    /** Seconds; a little above the helper's own limit, so the helper's message wins. */
    public const float TIMEOUT = 3700.0;

    public function __construct(
        private CommandRunner $runner,
        private PrivilegedApply $helper,
        private DriftReportStore $store,
        private InstalledVersion $version,
        private InstalledEngine $engine,
        private ClockInterface $clock,
        private string $playbookFile,
    ) {
    }

    /**
     * Why the check cannot run from this host right now, or null when it can.
     */
    public function unavailable(): ?string
    {
        if (!$this->helper->supports(ApplyTarget::Drift)) {
            return 'The root helper installed on this host does not know "apply drift" yet. Upgrade the host (sudo fleet upgrade), then come back.';
        }
        if (!is_file($this->playbookFile)) {
            return 'This host was not installed with the Ansible engine, so there is no playbook to check against. Re-run the installer with --engine=ansible first.';
        }

        return null;
    }

    /**
     * @throws DriftCheckFailed when no report came back (the helper refused, the run timed out, the playbook printed none)
     */
    public function run(): DriftReport
    {
        if (null !== $reason = $this->unavailable()) {
            throw new DriftCheckFailed($reason);
        }
        try {
            $result = $this->runner->run(['sudo', '-n', '--', PrivilegedApply::HELPER, ApplyTarget::Drift->value], timeout: self::TIMEOUT);
        } catch (CommandTimedOut) {
            throw new DriftCheckFailed('The drift check did not finish in time.');
        } catch (CommandRunnerException) {
            throw new DriftCheckFailed('The drift check could not start (sudo).');
        }

        // The playbook exits 2 when a task failed; its report is still the answer, the failed tasks are in it.
        try {
            $report = DriftReport::fromRunnerOutput($result->stdout, $this->version->raw(), $this->clock->now(), $this->engine->name());
        } catch (DriftReportInvalid $e) {
            throw new DriftCheckFailed(\sprintf('%s The run ended with exit %d: %s', $e->getMessage(), $result->exitCode, OutputTail::of($result)));
        }
        $this->store->save($report);

        return $report;
    }
}
