<?php

declare(strict_types=1);

namespace App\Api\Write;

use App\Api\Http\ApiProblem;
use App\Domain\Instance\Instance;

/**
 * The checks every API destroy goes through (single, batch and the retry of a destroy job): the project's `main`
 * instance is protected, and `If-Match` (the instance's `created_at`) is mandatory and must match.
 */
final class DestroyGuard
{
    private const string PROTECTED_LABEL = 'main';

    public static function check(Instance $instance, ?string $ifMatch): void
    {
        if (self::PROTECTED_LABEL === $instance->label()) {
            throw ApiProblem::conflict('protected_instance', 'The main instance of a project is protected and cannot be destroyed over the API.');
        }
        if (null === $ifMatch || '' === trim($ifMatch, ' "')) {
            throw ApiProblem::ifMatchRequired();
        }
        if (trim($ifMatch, ' "') !== $instance->createdAt()->format(\DATE_ATOM)) {
            throw ApiProblem::staleIfMatch();
        }
    }
}
