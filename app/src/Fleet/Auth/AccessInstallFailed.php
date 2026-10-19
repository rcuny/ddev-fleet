<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

/**
 * IdentitySync::commit() saved the change, but writing users.yml or installing the Authelia access rules failed. This is a
 * warning, not a rollback: the change stands and the install is retried by the next change (or refreshAccess()).
 */
final class AccessInstallFailed extends \RuntimeException
{
    public function __construct(
        /** What the committed change returned. */
        public readonly mixed $result,
        \Throwable $previous,
    ) {
        parent::__construct('The change was saved, but the sign-in service was not updated: '.$previous->getMessage(), 0, $previous);
    }
}
