<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * "CommandRunner is the only way the app runs a host command" as a guard, not a convention: direct process
 * execution is allowed in exactly one class, and nothing may depend on the concrete runners.
 */
final class HostCommandSeamTest extends TestCase
{
    /** The single class that may start processes. */
    private const PROCESS_OWNER = 'src/Fleet/Shell/ProcessCommandRunner.php';

    /** Concrete runners may be referenced only inside this directory (the factory lives there). */
    private const RUNNER_DIR = 'src/Fleet/Shell/';

    private const FORBIDDEN_FUNCTIONS = ['proc_open', 'popen', 'exec', 'shell_exec', 'passthru', 'system', 'pcntl_exec'];

    public function testOnlyTheProcessRunnerStartsProcesses(): void
    {
        $offenders = [];
        foreach (self::sources() as $relative => $code) {
            if (self::PROCESS_OWNER === $relative) {
                continue;
            }
            foreach (self::processViolations($code) as $violation) {
                $offenders[] = $relative.': '.$violation;
            }
        }

        self::assertSame([], $offenders, "host commands must go through App\\Fleet\\Shell\\CommandRunner:\n".implode("\n", $offenders));
    }

    public function testConcreteRunnersAreNotReferencedOutsideTheShellNamespace(): void
    {
        $offenders = [];
        foreach (self::sources() as $relative => $code) {
            if (str_starts_with($relative, self::RUNNER_DIR)) {
                continue;
            }
            foreach (self::concreteRunnerReferences($code) as $reference) {
                $offenders[] = $relative.': '.$reference;
            }
        }

        self::assertSame([], $offenders, "depend on the CommandRunner interface, not a concrete runner:\n".implode("\n", $offenders));
    }

    public function testScannerRecognisesEveryForbiddenForm(): void
    {
        $forbidden = [
            'new Process' => '<?php $p = new Process(["ls"]);',
            'new FQCN' => '<?php $p = new \Symfony\Component\Process\Process(["ls"]);',
            'fromShellCommandline' => '<?php $p = Process::fromShellCommandline("ls");',
            'proc_open' => '<?php proc_open("ls", [], $pipes);',
            'popen' => '<?php popen("ls", "r");',
            'exec' => '<?php exec("ls");',
            'exec qualified' => '<?php \exec("ls");',
            'shell_exec' => '<?php shell_exec("ls");',
            'passthru' => '<?php passthru("ls");',
            'system' => '<?php system("ls");',
            'backticks' => '<?php $x = `ls`;',
            'import' => '<?php use Symfony\Component\Process\Process;',
        ];
        foreach ($forbidden as $label => $code) {
            self::assertNotSame([], self::processViolations($code), $label);
        }

        $allowed = [
            'method named exec' => '<?php $pdo->exec("PRAGMA x");',
            'static method named system' => '<?php Foo::system();',
            'declaring exec' => '<?php class A { public function exec(): void {} }',
            'string and comment' => '<?php // exec("ls")'."\n".'$s = "new Process(\'x\')"; /* proc_open( */',
            'ExecutableFinder' => '<?php use Symfony\Component\Process\ExecutableFinder;',
            'another Process class' => '<?php $p = new \App\Domain\ProcessStep();',
        ];
        foreach ($allowed as $label => $code) {
            self::assertSame([], self::processViolations($code), $label);
        }

        self::assertNotSame([], self::concreteRunnerReferences('<?php use App\Fleet\Shell\ProcessCommandRunner;'));
        self::assertNotSame([], self::concreteRunnerReferences('<?php function f(\App\Fleet\Shell\RecordingCommandRunner $r) {}'));
        self::assertSame([], self::concreteRunnerReferences('<?php use App\Fleet\Shell\CommandRunner;'));
    }

    public function testConcreteRunnersAreNotAutowirable(): void
    {
        $services = (string) file_get_contents(self::root().'/config/services.yaml');

        // They are excluded from the App\ resource and registered under non-class ids.
        self::assertStringContainsString("'../src/Fleet/Shell/ProcessCommandRunner.php'", $services);
        self::assertStringContainsString("'../src/Fleet/Shell/RecordingCommandRunner.php'", $services);
        self::assertDoesNotMatchRegularExpression('/^\s*App\\\\Fleet\\\\Shell\\\\(Process|Recording)CommandRunner:/m', $services);
    }

    /**
     * @return list<string>
     */
    private static function processViolations(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $t): bool => !$t->isIgnorable(),
        ));
        $violations = [];
        foreach ($tokens as $i => $token) {
            if ('`' === $token->text) {
                $violations[] = \sprintf('line %d: backtick shell execution', $token->line);
                continue;
            }
            $name = ltrim($token->text, '\\');
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && str_starts_with($name, 'Symfony\Component\Process\Process')
                && !str_starts_with($name, 'Symfony\Component\Process\Process\\')) {
                $violations[] = \sprintf('line %d: references %s', $token->line, $name);
                continue;
            }
            if ($token->is(\T_STRING) && 'Process' === $token->text && null !== $next && ('(' === $next->text || $next->is(\T_DOUBLE_COLON)) && null !== $previous && $previous->is(\T_NEW)) {
                $violations[] = \sprintf('line %d: new Process(', $token->line);
                continue;
            }
            if ($token->is(\T_STRING) && 'fromShellCommandline' === $token->text) {
                $violations[] = \sprintf('line %d: Process::fromShellCommandline', $token->line);
                continue;
            }
            if ($token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED]) && \in_array($name, self::FORBIDDEN_FUNCTIONS, true)
                && null !== $next && '(' === $next->text
                && !(null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW]))) {
                $violations[] = \sprintf('line %d: %s(', $token->line, $name);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function concreteRunnerReferences(string $code): array
    {
        $references = [];
        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && 1 === preg_match('/(^|\\\\)(Process|Recording)CommandRunner$/D', $token->text)) {
                $references[] = \sprintf('line %d: %s', $token->line, $token->text);
            }
        }

        return $references;
    }

    /**
     * @return \Generator<string, string> path relative to app/ => source
     */
    private static function sources(): \Generator
    {
        $root = self::root();
        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile() && 'php' === $file->getExtension()) {
                ++$count;
                yield substr($file->getPathname(), \strlen($root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
        self::assertGreaterThan(10, $count, 'app/src must yield php files, or the guard scans nothing');
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
