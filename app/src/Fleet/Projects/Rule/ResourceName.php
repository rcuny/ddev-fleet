<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

use App\Domain\Project\Project;

/**
 * Names of projects, templates and ports (decision P2). Same shape as the 0.x `validate_part`
 * (`^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`, no `--`: the double dash separates project and label in an instance id), at most
 * 40 characters so that `<project>--<label>` keeps room inside the 63-character DNS label.
 */
final class ResourceName
{
    /** The column length of the entities (Project::NAME_MAX), so the rule and the schema cannot drift apart. */
    public const int MAX = Project::NAME_MAX;

    /** `new` is the URL of the create page. */
    public const array RESERVED = ['new'];

    private const string PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D';

    /**
     * @param bool $checkReserved false when looking an existing record up (the reserved names are only refused on create)
     */
    public static function error(mixed $name, bool $checkReserved = true): ?string
    {
        if (!\is_string($name) || '' === $name) {
            return 'Enter a name.';
        }
        if (\strlen($name) > self::MAX) {
            return \sprintf('The name must be at most %d characters.', self::MAX);
        }
        if (1 !== preg_match(self::PATTERN, $name)) {
            return 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';
        }
        if (str_contains($name, '--')) {
            return 'The name must not contain two dashes in a row ("--" separates the project from the instance label).';
        }
        if ($checkReserved && \in_array($name, self::RESERVED, true)) {
            return 'This name is reserved.';
        }

        return null;
    }

    /**
     * An extra hostname label (the alias is `<label>-<project>--<instance label>`, a single 63-character DNS label):
     * the label must leave at least one character for the instance label.
     */
    public static function hostnameError(mixed $label, string $projectName): ?string
    {
        if (!\is_string($label) || '' === $label) {
            return 'Enter a hostname label.';
        }
        if (1 !== preg_match(self::PATTERN, $label) || str_contains($label, '--')) {
            return 'A hostname label uses lowercase letters, digits and single dashes only (no dots).';
        }
        // <label>-<project>--<instance label>: 1 + 2 separator characters and at least 1 character of instance label
        if (\strlen($label) + 1 + \strlen($projectName) + 2 + 1 > 63) {
            return 'This hostname label is too long for this project name: the whole host name label is limited to 63 characters.';
        }

        return null;
    }
}
