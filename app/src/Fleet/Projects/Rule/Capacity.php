<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The capacity allotments of a project or a template (FLE-197): CPU threads and slots are whole numbers of at least 1,
 * null meaning unlimited (project) or inherited (template). A value above the host's allocatable threads is not an
 * error here: it is capped at run time.
 */
final class Capacity
{
    /** The largest accepted number: well above any host, and a bound that keeps the quota arithmetic far from overflow. */
    public const int MAX = 100000;

    private function __construct()
    {
    }

    public static function error(?int $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value < 1) {
            return 'Enter a whole number of at least 1, or leave it empty.';
        }

        return $value > self::MAX ? \sprintf('At most %d.', self::MAX) : null;
    }

    /**
     * The message for a template value that is above its project's value, or null.
     *
     * @param string $what 'CPU threads' or 'Slots'
     */
    public static function templateAboveProject(string $what, ?int $template, ?int $project): ?string
    {
        if (null === $template || null === $project || $template <= $project) {
            return null;
        }

        return \sprintf('%s: the template asks for %d, more than the project\'s %d.', $what, $template, $project);
    }

    /**
     * The message for a project value below a template's explicit one, naming the template, or null.
     *
     * @param string $what 'CPU threads' or 'Slots'
     */
    public static function projectBelowTemplate(string $what, ?int $project, string $template, ?int $value): ?string
    {
        if (null === $project || null === $value || $value <= $project) {
            return null;
        }

        return \sprintf('%s: template "%s" is set to %d, more than %d. Lower the template first.', $what, $template, $value, $project);
    }
}
