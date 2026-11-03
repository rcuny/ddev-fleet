<?php

declare(strict_types=1);

// symfony-design-system:start

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The design system page renders its samples with Twig's string loader, which has no sandbox.
 * Only the Section component may call it, and only with committed, static sample strings.
 */
final class DesignSystemSampleRendererTest extends TestCase
{
    public function testTemplateFromStringIsUsedOnlyBySectionComponent(): void
    {
        $templates = \dirname(__DIR__, 2).'/templates';
        $users = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'template_from_string')) {
                $users[] = substr($file->getPathname(), \strlen($templates) + 1);
            }
        }

        self::assertSame(['components/Ds/Section.html.twig'], $users);
    }
}

// symfony-design-system:end
