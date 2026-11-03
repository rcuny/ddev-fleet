<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Domain\Port\NamedPort;
use App\Domain\Template\Template;

/**
 * The JSON objects of a template and a named port, in the key names of ProjectSnapshot (a project object lists its
 * templates the same way).
 */
final class Shapes
{
    private const string TIME = 'Y-m-d\TH:i:s\Z';

    /**
     * @return array<string, mixed>
     */
    public static function template(Template $template): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'project' => $template->project()->name(),
            'name' => $template->name(),
            'drupal_env' => $template->drupalEnv(),
            'post_deploy' => [
                'exec' => $template->postDeploy(),
                'tty1' => $template->tty1(),
                'tty2' => $template->tty2(),
            ],
            'created_at' => $template->createdAt()->setTimezone($utc)->format(self::TIME),
            'updated_at' => $template->updatedAt()->setTimezone($utc)->format(self::TIME),
        ];
    }

    /**
     * @param list<string> $projects names of the projects that have the port assigned
     *
     * @return array<string, mixed>
     */
    public static function port(NamedPort $port, array $projects): array
    {
        return [
            'name' => $port->name(),
            'public' => $port->publicPort(),
            'router' => $port->routerPort(),
            'projects' => $projects,
        ];
    }
}
