<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

/**
 * The systemd slice names of the capacity tree (spec 2.3). systemd derives a slice's parent from the dashes in its
 * name, and project and template names contain dashes, so a component is encoded `-` -> `_` (injective: names match
 * [a-z0-9-]+ and never contain `_`): project "ddev-fleet-ci", template "gates" = `fleet-ddev_fleet_ci-gates.slice`.
 */
final class SliceName
{
    public const string ROOT = 'fleet.slice';

    private function __construct()
    {
    }

    public static function project(string $project): string
    {
        return \sprintf('fleet-%s.slice', self::encode($project));
    }

    public static function template(string $project, string $template): string
    {
        return \sprintf('fleet-%s-%s.slice', self::encode($project), self::encode($template));
    }

    private static function encode(string $name): string
    {
        return str_replace('-', '_', $name);
    }
}
