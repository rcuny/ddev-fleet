<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\FleetPaths;
use App\Fleet\Projects\ValidationFailed;

/**
 * The instance id is `{project}--{label}`, at most 59 characters: DDEV names the web container's host name `<id>-web`, and a host name has at most 63
 * characters, so Docker refuses a longer one at `ddev start`. Labels are allocated against the ids
 * that are taken: the bare label while it is free, then `-1`, `-2`, ... A batch never uses the bare label.
 */
final class InstanceNaming
{
    /** 63 (host name limit) minus the 4 characters of DDEV's `-web` suffix. */
    public const int MAX = 59;

    /** A single DNS label (the `<hostname>-<id>` alias of an instance). */
    public const int DNS_LABEL_MAX = 63;

    /**
     * @throws ValidationFailed when the id would be longer than 59 characters
     */
    public static function compose(string $project, string $label): string
    {
        $id = $project.'--'.$label;
        if (\strlen($id) > self::MAX) {
            throw new ValidationFailed(['label' => \sprintf('The instance id would be %d characters; the limit is %d (DDEV adds -web to the container host name, and a host name has at most 63 characters). Use a shorter label.', \strlen($id), self::MAX)]);
        }

        return $id;
    }

    /**
     * Cuts a label so the instance id (and, for a project with additional hostnames, the `<hostname>-<id>` alias) fits
     * in 59 characters. A label that is cut ends in `-` and the first 6 hex of the SHA-1 of `$seed`, so the same seed
     * always lands on the same id.
     *
     * @param int $aliasPrefix characters an alias adds in front of the id (`InstanceHostnames::longestPrefix()`)
     * @param int $reserve     characters kept free behind the label, e.g. 4 for a `-999` allocation suffix
     *
     * @throws ValidationFailed when not even the hash fits
     */
    public static function fitLabel(string $project, string $label, string $seed, int $aliasPrefix = 0, int $reserve = 0): string
    {
        $budget = min(self::MAX, self::DNS_LABEL_MAX - $aliasPrefix) - \strlen($project) - 2 - $reserve;
        if (\strlen($label) <= $budget) {
            return $label;
        }
        $suffix = '-'.substr(sha1($seed), 0, 6);
        $keep = $budget - \strlen($suffix);
        if ($keep < 1) {
            throw new ValidationFailed(['label' => 'The project name is too long to leave room for a label.']);
        }

        return rtrim(substr($label, 0, $keep), '-').$suffix;
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

    /**
     * True for an id that may already exist: the creation rule plus the 60 to 63 characters older versions allowed.
     */
    public static function isExistingInstanceId(string $text): bool
    {
        return 1 === preg_match(FleetPaths::EXISTING_INSTANCE_ID_PATTERN, $text);
    }

    public static function isInstanceId(string $text): bool
    {
        return 1 === preg_match(FleetPaths::INSTANCE_ID_PATTERN, $text);
    }
}
