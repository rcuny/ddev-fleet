<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

/**
 * Fixed DDEV paths. The one file that may spell the container approot literally: RepoHygieneTest allow-lists it.
 */
final class DdevPaths
{
    /** DDEV's fixed approot inside the web container (not the companion workspace); the project checkout is mounted there. */
    public const string CONTAINER_APPROOT = '/var/www/html';

    private function __construct()
    {
    }
}
