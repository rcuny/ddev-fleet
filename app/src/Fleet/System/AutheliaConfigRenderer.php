<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Fleet\FleetPaths;
use Twig\Environment;

/**
 * Renders Authelia's main configuration (installed as root through `apply authelia`). The secrets (session secret,
 * storage encryption key, reset-password JWT secret) are deliberately absent: the installer supplies them through
 * AUTHELIA_*_FILE variables. Only users.yml changes after the first install, and that file is the app's.
 */
final readonly class AutheliaConfigRenderer
{
    /** Where Authelia keeps its SQLite database and notification file (owned by the authelia user). */
    public const string STATE_DIR = '/var/lib/authelia';

    public function __construct(
        private Environment $twig,
        private FleetPaths $paths,
    ) {
    }

    public function render(HostSettings $host): string
    {
        return $this->twig->render('system/authelia-configuration.twig', [
            'domain' => ConfigValue::domain($host->domain()),
            'address' => CaddyfileRenderer::AUTHELIA,
            'users_file' => ConfigValue::path('The Authelia users file', $this->paths->usersFile()),
            'state_dir' => self::STATE_DIR,
        ]);
    }
}
