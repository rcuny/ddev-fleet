<?php

declare(strict_types=1);

namespace App\Api\Read;

/**
 * The dispatch gate of the `ddev-fleet` skill, server-side: may one more run start for project/template? It reads the
 * schema-1 capacity report (HostCapacity::report) and answers `{ok, reasons[]}`. Reasons: `template_slots_full`,
 * `project_slots_full`, `cpu_full`, `instance_cap`.
 *
 * Dispatch only when: busy < slots at the template and at the project (each only when set);
 * used_cores(project) + run need <= effective threads of the project (run need = template effective threads / template
 * slots, FALLBACK_RUN_NEED when the template has no slots); instances.count < instances.max.
 */
final class CapacityVerdict
{
    /** Threads one run needs when the template has no slots. */
    public const int FALLBACK_RUN_NEED = 2;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $report HostCapacity::report()
     *
     * @return array{project: string, template: string, ok: bool, reasons: list<string>, slots: array<string, mixed>, cores: array<string, float>, instances: array<string, int>}|null null = the project is not in the report
     */
    public static function evaluate(array $report, string $project, string $template): ?array
    {
        $found = null;
        foreach (\is_array($report['projects'] ?? null) ? $report['projects'] : [] as $candidate) {
            if (\is_array($candidate) && ($candidate['name'] ?? null) === $project) {
                $found = $candidate;
                break;
            }
        }
        if (null === $found) {
            return null;
        }
        $tpl = null;
        foreach (\is_array($found['templates'] ?? null) ? $found['templates'] : [] as $candidate) {
            if (\is_array($candidate) && ($candidate['name'] ?? null) === $template) {
                $tpl = $candidate;
                break;
            }
        }

        $projectSlots = self::int($found['slots'] ?? null);
        $projectBusy = self::int($found['busy'] ?? 0) ?? 0;
        $templateSlots = null === $tpl ? null : self::int($tpl['slots'] ?? null);
        $templateBusy = null === $tpl ? 0 : (self::int($tpl['busy'] ?? 0) ?? 0);
        $projectThreads = (float) ($found['effective_threads'] ?? 0);
        $templateThreads = null === $tpl ? $projectThreads : (float) ($tpl['effective_threads'] ?? $projectThreads);
        $used = (float) ($found['used_cores'] ?? 0);
        $instances = \is_array($report['instances'] ?? null) ? $report['instances'] : [];
        $count = (int) ($instances['count'] ?? 0);
        $max = (int) ($instances['max'] ?? 0);

        $need = null !== $templateSlots && $templateSlots > 0 ? $templateThreads / $templateSlots : (float) self::FALLBACK_RUN_NEED;
        $reasons = [];
        if (null !== $templateSlots && $templateBusy >= $templateSlots) {
            $reasons[] = 'template_slots_full';
        }
        if (null !== $projectSlots && $projectBusy >= $projectSlots) {
            $reasons[] = 'project_slots_full';
        }
        if ($used + $need > $projectThreads) {
            $reasons[] = 'cpu_full';
        }
        if ($count >= $max) {
            $reasons[] = 'instance_cap';
        }

        return [
            'project' => $project,
            'template' => $template,
            'ok' => [] === $reasons,
            'reasons' => $reasons,
            'slots' => [
                'template' => ['busy' => $templateBusy, 'slots' => $templateSlots],
                'project' => ['busy' => $projectBusy, 'slots' => $projectSlots],
            ],
            'cores' => ['used' => $used, 'need' => $need, 'effective_threads' => $projectThreads],
            'instances' => ['count' => $count, 'max' => $max],
        ];
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
