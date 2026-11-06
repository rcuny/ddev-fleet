<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Renders the systemd slice tree of the capacity allotments as the JSON the root helper reads (`apply slices`,
 * /srv/fleet/staging/slices/slices.json): a list of {unit, quota_percent|null}.
 *
 *   fleet.slice                  CPUQuota = allocatable * 100%
 *   fleet-<p>.slice              project limit * 100% (null: unlimited, the slice still exists for accounting)
 *   fleet-<p>-<t>.slice          template limit * 100% (null: inherits)
 *
 * systemd derives a slice's parent from the dashes of its name, and project/template names contain dashes, so each
 * component has its `-` encoded as `_`. That is injective: names match [a-z0-9-]+ and never contain `_`.
 * The helper validates all of it again; this class only produces it.
 */
final class SlicesRenderer
{
    public const string ROOT_UNIT = 'fleet.slice';

    /**
     * @param int<1, max>                                                                                                 $allocatableThreads logical CPUs minus the host reserve
     * @param list<array{name: string, cpuThreads: int|null, templates: list<array{name: string, cpuThreads: int|null}>}> $projects
     */
    public function render(int $allocatableThreads, array $projects): string
    {
        return json_encode($this->units($allocatableThreads, $projects), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param int<1, max>                                                                                                 $allocatableThreads
     * @param list<array{name: string, cpuThreads: int|null, templates: list<array{name: string, cpuThreads: int|null}>}> $projects
     *
     * @return list<array{unit: string, quota_percent: int|null}>
     */
    public function units(int $allocatableThreads, array $projects): array
    {
        $units = [['unit' => self::ROOT_UNIT, 'quota_percent' => $allocatableThreads * 100]];
        foreach ($projects as $project) {
            $projectThreads = null === $project['cpuThreads'] ? null : min($project['cpuThreads'], $allocatableThreads);
            $units[] = ['unit' => self::unitName($project['name']), 'quota_percent' => null === $projectThreads ? null : $projectThreads * 100];
            foreach ($project['templates'] as $template) {
                $threads = null === $template['cpuThreads'] ? null : min($template['cpuThreads'], $projectThreads ?? $allocatableThreads);
                $units[] = ['unit' => self::unitName($project['name'], $template['name']), 'quota_percent' => null === $threads ? null : $threads * 100];
            }
        }

        return $units;
    }

    /**
     * The slice a project's (or one of its templates') containers live in.
     */
    public static function unitName(string $project, ?string $template = null): string
    {
        $name = 'fleet-'.self::encode($project);
        if (null !== $template) {
            $name .= '-'.self::encode($template);
        }

        return $name.'.slice';
    }

    private static function encode(string $component): string
    {
        return str_replace('-', '_', $component);
    }
}
