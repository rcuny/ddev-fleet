<?php

declare(strict_types=1);

namespace App\Command\Secret;

use Symfony\Component\Console\Input\InputInterface;

/**
 * What the secret commands read the same way: the `--project` option.
 */
final class SecretOptions
{
    /**
     * @return string|null the project name, or null for the fleet-wide scope
     */
    public static function project(InputInterface $input): ?string
    {
        $project = $input->getOption('project');

        return \is_string($project) && '' !== $project ? $project : null;
    }
}
