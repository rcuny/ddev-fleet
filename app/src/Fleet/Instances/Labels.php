<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Projects\ValidationFailed;

/**
 * Turns text (a branch, an issue id, whatever the user typed) into an instance label: lowercase words joined by single
 * dashes.
 */
final class Labels
{
    /**
     * @throws ValidationFailed when nothing usable is left
     */
    public static function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if ('' === $slug) {
            throw new ValidationFailed(['label' => 'The label needs at least one letter or digit.']);
        }

        return $slug;
    }
}
