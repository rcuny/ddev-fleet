<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * For KernelTestCase subclasses that use FleetDatabase: runs a console command the way a new process would (the
 * entity manager is cleared first, so no earlier entity or collection is reused) with stdout and stderr apart.
 */
trait RunsFleetCommands
{
    /**
     * @param array<string, mixed> $input   arguments and options as the console takes them: `name`, `--git`, ...
     * @param list<string>         $answers typed at an interactive prompt
     */
    private function cli(string $command, array $input = [], array $answers = [], bool $interactive = false): CommandTester
    {
        $this->entityManager()->clear();
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find($command));
        $tester->setInputs($answers);
        $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => $interactive]);

        return $tester;
    }

    /**
     * @return array<mixed>
     */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }
}
