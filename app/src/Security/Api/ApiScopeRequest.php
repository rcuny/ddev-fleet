<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Domain\ApiToken\ApiScope;

/** The question put to ScopeVoter: may the token use $scope, on $project when one is named. */
final readonly class ApiScopeRequest
{
    public function __construct(public ApiScope $scope, public ?string $project = null)
    {
    }
}
