<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * The project, template or named port asked for does not exist.
 */
final class NotFound extends \DomainException
{
    public static function project(string $name): self
    {
        return new self(\sprintf('Project "%s" does not exist.', self::shown($name)));
    }

    public static function template(string $project, string $name): self
    {
        return new self(\sprintf('Template "%s" does not exist in project "%s".', self::shown($name), self::shown($project)));
    }

    public static function port(string $name): self
    {
        return new self(\sprintf('Named port "%s" does not exist.', self::shown($name)));
    }

    /**
     * The name comes from a URL or a command line: cut it and drop control characters before it lands in a message.
     */
    private static function shown(string $name): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '';

        return \strlen($clean) > 60 ? substr($clean, 0, 57).'...' : $clean;
    }
}
