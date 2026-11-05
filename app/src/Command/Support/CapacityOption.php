<?php

declare(strict_types=1);

namespace App\Command\Support;

use Symfony\Component\Console\Input\InputInterface;

/**
 * The --cpu-threads and --slots options of the project and template commands: a whole number, or `none` for no value
 * (unlimited on a project, inherited on a template). Range rules belong to the services (Rule\Capacity).
 */
final class CapacityOption
{
    private const array OPTIONS = ['cpu-threads' => 'cpuThreads', 'slots' => 'slots'];

    private function __construct()
    {
    }

    /**
     * Adds the given options to $changes (by input property name) and returns the field errors of the words it cannot read.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, string>
     */
    public static function collect(InputInterface $input, array &$changes): array
    {
        $errors = [];
        foreach (self::OPTIONS as $option => $property) {
            $word = $input->hasOption($option) ? $input->getOption($option) : null;
            if (null === $word) {
                continue;
            }
            $word = trim((string) $word);
            if ('none' === strtolower($word)) {
                $changes[$property] = null;
            } elseif (1 === preg_match('/^[0-9]{1,9}$/D', $word)) {
                $changes[$property] = (int) $word;
            } else {
                $errors[$property] = 'Enter a whole number or none.';
            }
        }

        return $errors;
    }
}
