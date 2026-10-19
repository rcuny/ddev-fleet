<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * One or more submitted values were refused. The map is field name => message (the form field names and the CLI option
 * names); it never contains a submitted value, so it is safe to show and to log.
 */
final class ValidationFailed extends \DomainException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid values: '.implode(', ', array_keys($errors)).'.');
    }
}
