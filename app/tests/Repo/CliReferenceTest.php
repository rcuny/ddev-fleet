<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\Docs\CliReference;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/reference/01-cli.md is generated from the console definition, so every command, option and argument is documented
 * by construction. This test fails when the committed page differs from a fresh rendering.
 */
final class CliReferenceTest extends KernelTestCase
{
    public function testTheCommittedReferenceIsUpToDate(): void
    {
        $file = \dirname(__DIR__, 3).'/docs/reference/01-cli.md';
        self::assertFileExists($file);

        $application = new Application(self::bootKernel(['environment' => 'test', 'debug' => false]));
        $expected = new CliReference()->render($application);

        self::assertSame($expected, (string) file_get_contents($file), 'docs/reference/01-cli.md is stale (the console commands changed). Regenerate it with `'.CliReference::REGENERATE.'` and commit the result.');
    }
}
