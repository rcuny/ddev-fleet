<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

/**
 * The change is valid but would lock the fleet or the person making it out (see LastAdminGuard); the message says why and is
 * safe to show. The CLI maps it to its "not allowed now" exit code (2), like Refused for projects.
 */
final class UserChangeRefused extends \DomainException
{
}
