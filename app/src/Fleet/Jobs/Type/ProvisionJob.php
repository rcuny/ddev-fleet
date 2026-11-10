<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Job\Job;
use App\Fleet\Host\Provision\HardeningSettings;
use App\Fleet\Host\Provision\HostVarsReader;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\HostVarsRenderer;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\SystemRenderer;

/**
 * Applies the host's optional roles (FLE-260): renders host-vars.yml from the job's answers, stages it, and runs the root
 * helper `apply provision`, which validates and installs it and runs the installed tag's playbook. The playbook's output
 * is the job log, line by line. The job fixes nothing about the playbook: no path, role or argument is a parameter.
 * Params: enabled, sshAllowUsers, trustedIps, graceMinutes, hardenTmp, invokingUser.
 */
final readonly class ProvisionJob implements JobType
{
    /** Seconds; a little above the helper's own limit, so the helper's message wins. */
    public const float TIMEOUT = 3700.0;

    public function __construct(
        private HostSettingsRepository $host,
        private HostVarsReader $current,
        private HostVarsRenderer $renderer,
        private SystemRenderer $system,
        private CommandRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'provision';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $host = $this->host->get() ?? throw new JobFailed('This host is not initialised.');
        $settings = self::settings($job->params());
        if ([] !== $settings->violations()) {
            throw new JobFailed('The hardening settings are not valid.');
        }

        try {
            $text = $this->renderer->render(
                $host->domain(),
                $host->acmeEmail(),
                $this->current->shellProfile(),
                $settings->enabled,
                $settings->sshAllowUsers,
                $settings->trustedIps,
                $settings->graceMinutes,
                $settings->hardenTmp,
                $settings->enabled ? $settings->invokingUser : null,
            );
            $this->system->stageFile(ApplyTarget::Provision, 'host-vars.yml', $text);
        } catch (\InvalidArgumentException|\RuntimeException) {
            throw new JobFailed('The host settings could not be prepared.');
        }

        $log->line('Applying the host settings: hardening '.($settings->enabled ? 'on' : 'off').'.');
        try {
            $result = $this->runner->run(['sudo', '-n', '--', PrivilegedApply::HELPER, ApplyTarget::Provision->value], timeout: self::TIMEOUT, onOutput: $log->output(...));
        } catch (CommandRunnerException $e) {
            throw new JobFailed('The provisioning run did not finish: '.$e->getMessage());
        } finally {
            $this->system->discardStaging(ApplyTarget::Provision);
        }
        if (!$result->isSuccess()) {
            throw new JobFailed(\sprintf('The provisioning run failed (exit %d). The log above shows where.', $result->exitCode), $result->exitCode);
        }
        if ($settings->enabled) {
            $log->line('Done. If the firewall was enabled, confirm it from a SECOND SSH session: sudo fleet-firewall-confirm. Until then the dead-man\'s switch turns it off again.');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function settings(array $params): HardeningSettings
    {
        $list = static fn (mixed $v): array => \is_array($v) ? array_values(array_filter($v, \is_string(...))) : [];
        $grace = $params['graceMinutes'] ?? HardeningSettings::GRACE_DEFAULT;

        return new HardeningSettings(
            true === ($params['enabled'] ?? false),
            $list($params['sshAllowUsers'] ?? []),
            $list($params['trustedIps'] ?? []),
            \is_int($grace) ? $grace : HardeningSettings::GRACE_DEFAULT,
            true === ($params['hardenTmp'] ?? false),
            \is_string($params['invokingUser'] ?? null) ? $params['invokingUser'] : null,
        );
    }
}
