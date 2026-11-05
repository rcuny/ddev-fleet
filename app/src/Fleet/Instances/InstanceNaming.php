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

    /**
     * Characters kept for the service name in DDEV's container name `ddev-<id>-<service>` (a DNS label for Docker's
     * embedded DNS, so at most 63). 20 covers `typesense-dashboard` (19), the longest add-on service we ship.
     */
    public const int SERVICE_BUDGET = 20;

    /**
     * The longest add-on service name we ship (`typesense-dashboard`, 19 characters, within SERVICE_BUDGET). The
     * budget is only applied to projects with Typesense (`Project::typesense()`), the one source of add-on services:
     * see `serviceBudgetFor()`.
     */
    private const string LONGEST_SERVICE = 'typesense-dashboard';

    public static function longestServiceName(): string
    {
        return self::LONGEST_SERVICE;
    }

    /** Characters to keep for the service name of `ddev-<id>-<service>`: SERVICE_BUDGET for a project with add-on services, else 0 (web and db fit any id of 59). */
    public static function serviceBudgetFor(bool $hasAddonServices): int
    {
        return $hasAddonServices ? self::SERVICE_BUDGET : 0;
    }

    /** Never cut a label below this many characters (hash included), whatever the project name or service budget. */
    private const int MIN_LABEL = 12;

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
     * Like compose() for an id that is about to be created: with a service budget the id must also leave room for
     * `ddev-<id>-<service>` within 63 characters, otherwise Docker's DNS cannot resolve the add-on containers.
     *
     * @throws ValidationFailed
     */
    public static function composeNew(string $project, string $label, int $serviceBudget): string
    {
        $id = self::compose($project, $label);
        $max = self::DNS_LABEL_MAX - 6 - $serviceBudget;
        if ($serviceBudget > 0 && \strlen($id) > $max) {
            throw new ValidationFailed(['label' => \sprintf('The instance id would be %d characters; this project has add-on services (Typesense) and the limit is %d so that ddev-<id>-<service> stays within 63 characters. Use a shorter label.', \strlen($id), $max)]);
        }

        return $id;
    }

    /**
     * Cuts a label so the instance id (and, for a project with additional hostnames, the `<hostname>-<id>` alias) fits
     * in 59 characters. A label that is cut ends in `-` and the first 6 hex of the SHA-1 of `$seed`, so the same seed
     * always lands on the same id.
     *
     * @param int $aliasPrefix   characters an alias adds in front of the id (`InstanceHostnames::longestPrefix()`)
     * @param int $reserve       characters kept free behind the label, e.g. 4 for a `-999` allocation suffix
     * @param int $serviceBudget characters kept for the longest service name of `ddev-<id>-<service>` (0 = no service cap)
     *
     * @throws ValidationFailed when not even the hash fits
     */
    public static function fitLabel(string $project, string $label, string $seed, int $aliasPrefix = 0, int $reserve = 0, int $serviceBudget = self::SERVICE_BUDGET): string
    {
        $cap = min(self::MAX, self::DNS_LABEL_MAX - $aliasPrefix);
        if ($serviceBudget > 0) {
            // 'ddev-' + id + '-' + service <= 63; never squeeze the label below MIN_LABEL for a long project name.
            $cap = min($cap, max(self::DNS_LABEL_MAX - 6 - $serviceBudget, \strlen($project) + 2 + self::MIN_LABEL + $reserve));
        }
        $budget = $cap - \strlen($project) - 2 - $reserve;
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
     * Container names (`ddev-<id>-<service>`) that exceed the 63-character DNS label limit, so Docker's embedded DNS
     * cannot resolve them (the router answers 502 for that service).
     *
     * @param list<string> $services service names of the instance (web, db, add-ons)
     *
     * @return list<string> the offending container names
     */
    public static function overlongContainers(string $id, array $services): array
    {
        $bad = [];
        foreach ($services as $service) {
            $name = 'ddev-'.$id.'-'.$service;
            if (\strlen($name) > self::DNS_LABEL_MAX) {
                $bad[] = $name;
            }
        }

        return $bad;
    }

    /**
     * @param list<string> $taken instance ids that exist (database rows and checkouts on disk)
     *
     * @return string the label: $base when free, else the first free `$base-N`
     */
    public static function allocateFree(array $taken, string $project, string $base, int $serviceBudget = 0): string
    {
        $taken = array_flip($taken);
        if (!isset($taken[self::composeNew($project, $base, $serviceBudget)])) {
            return $base;
        }
        for ($n = 1;; ++$n) {
            $label = $base.'-'.$n;
            if (!isset($taken[self::composeNew($project, $label, $serviceBudget)])) {
                return $label;
            }
        }
    }

    /**
     * @param list<string> $taken
     *
     * @return list<string> $count labels `$base-1` ... skipping numbers whose id is taken; never the bare base
     */
    public static function allocateBatch(array $taken, string $project, string $base, int $count, int $serviceBudget = 0): array
    {
        $taken = array_flip($taken);
        $labels = [];
        for ($n = 1; \count($labels) < $count; ++$n) {
            $label = $base.'-'.$n;
            if (!isset($taken[self::composeNew($project, $label, $serviceBudget)])) {
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
