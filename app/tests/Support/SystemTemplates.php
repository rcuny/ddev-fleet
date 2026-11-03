<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A Twig environment over the real app/templates with the default (html) autoescape and strict variables, so a
 * system template that forgets `{% autoescape false %}` or reads an undefined variable fails the test.
 */
final class SystemTemplates
{
    public static function dir(): string
    {
        return \dirname(__DIR__, 2).'/templates';
    }

    public static function twig(): Environment
    {
        return new Environment(new FilesystemLoader(self::dir()), ['strict_variables' => true]);
    }
}
