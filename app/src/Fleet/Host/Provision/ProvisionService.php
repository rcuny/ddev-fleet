<?php

declare(strict_types=1);

namespace App\Fleet\Host\Provision;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\Refused;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\PrivilegedApply;
use Psr\Clock\ClockInterface;

/**
 * Queues "apply the host's optional roles" (`apply provision`) as a job: a request never runs the playbook. The job
 * carries the validated answers (no secret in them); the worker renders host-vars.yml from them, stages it and has the
 * root helper install it and run the installed tag's playbook.
 */
final readonly class ProvisionService
{
    public const string JOB_TYPE = 'provision';

    public function __construct(
        private JobService $jobs,
        private JobRepository $repository,
        private HostSettingsRepository $host,
        private PrivilegedApply $helper,
        private AuditLog $audit,
        private ClockInterface $clock,
        private string $playbookFile,
    ) {
    }

    /**
     * Why optional roles cannot be applied from this host right now, or null when they can.
     */
    public function unavailable(): ?string
    {
        if (!$this->helper->supports(ApplyTarget::Provision)) {
            return 'The root helper installed on this host does not know "apply provision" yet. Upgrade the host (sudo fleet upgrade), then come back.';
        }
        if (!is_file($this->playbookFile)) {
            return 'This host was not installed with the Ansible engine, so there is no playbook to apply. Re-run the installer with --engine=ansible first.';
        }

        return null;
    }

    public function activeJob(): ?Job
    {
        return $this->repository->activeOfType(self::JOB_TYPE);
    }

    public function latestJob(): ?Job
    {
        return $this->repository->latestOfType(self::JOB_TYPE);
    }

    /**
     * @throws Refused when the settings are not valid, a run is already queued or running, or this host cannot apply them
     */
    public function queue(HardeningSettings $hardening, string $actor): Job
    {
        if (null !== $reason = $this->unavailable()) {
            throw new Refused($reason);
        }
        if ([] !== $hardening->violations()) {
            throw new Refused('The hardening settings are not valid.');
        }
        if (null !== $this->activeJob()) {
            throw new Refused('A provisioning run is already queued or running. Wait for it to finish.');
        }
        if (null === $this->host->get()) {
            throw new Refused('This host is not initialised.');
        }

        $job = new Job(self::JOB_TYPE, null, null, JobTrigger::Ui, $actor, [
            'enabled' => $hardening->enabled,
            'sshAllowUsers' => $hardening->sshAllowUsers,
            'trustedIps' => $hardening->trustedIps,
            'graceMinutes' => $hardening->graceMinutes,
            'hardenTmp' => $hardening->hardenTmp,
            'invokingUser' => $hardening->invokingUser,
        ], now: $this->clock->now());
        $this->jobs->enqueue($job);
        $this->audit->record($actor, AuditLog::HOST_PROVISION, \sprintf(
            'hardening %s, %d ssh account(s), %d trusted address(es), grace %d min, /tmp %s',
            $hardening->enabled ? 'on' : 'off',
            \count($hardening->sshAllowUsers),
            \count($hardening->trustedIps),
            $hardening->graceMinutes,
            $hardening->hardenTmp ? 'hardened' : 'unchanged',
        ));

        return $job;
    }
}
