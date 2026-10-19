<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\FleetPaths;
use App\Fleet\Projects\ValidationFailed;

/**
 * The instance id is `{project}--{label}`, at most 63 characters (a DNS label). Labels are allocated against the ids
 * that are taken: the bare label while it is free, then `-1`, `-2`, ... A batch never uses the bare label.
 */
final class InstanceNaming
{
    public const int MAX = 63;

    /**
     * @throws ValidationFailed when the id would be longer than 63 characters
     */
    public static function compose(string $project, string $label): string
    {
        $id = $project.'--'.$label;
        if (\strlen($id) > self::MAX) {
            throw new ValidationFailed(['label' => \sprintf('The instance id would be %d characters; the limit is %d. Use a shorter label.', \strlen($id), self::MAX)]);
        }

        return $id;
    }

    /**
     * @param list<string> $taken instance ids that exist (database rows and checkouts on disk)
     *
     * @return string the label: $base when free, else the first free `$base-N`
     */
    public static function allocateFree(array $taken, string $project, string $base): string
    {
        $taken = array_flip($taken);
        if (!isset($taken[self::compose($project, $base)])) {
            return $base;
        }
        for ($n = 1;; ++$n) {
            $label = $base.'-'.$n;
            if (!isset($taken[self::compose($project, $label)])) {
                return $label;
            }
        }
    }

    /**
     * @param list<string> $taken
     *
     * @return list<string> $count labels `$base-1` ... skipping numbers whose id is taken; never the bare base
     */
    public static function allocateBatch(array $taken, string $project, string $base, int $count): array
    {
        $taken = array_flip($taken);
        $labels = [];
        for ($n = 1; \count($labels) < $count; ++$n) {
            $label = $base.'-'.$n;
            if (!isset($taken[self::compose($project, $label)])) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public static function isInstanceId(string $text): bool
    {
        return 1 === preg_match(FleetPaths::INSTANCE_ID_PATTERN, $text);
    }
}
