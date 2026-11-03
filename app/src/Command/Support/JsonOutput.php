<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the project, template and port commands share: the --format option, the exit codes, and the two ways of
 * printing a result (a person's text, or one JSON document on stdout and nothing on stderr).
 *
 * Exit codes: 0 done, 1 a value was refused (field errors), 2 the operation is not allowed now (still has instances,
 * default template, port in use, no --yes), 3 the project, template or port does not exist.
 */
final class JsonOutput
{
    public const int OK = 0;
    public const int INVALID = 1;
    public const int REFUSED = 2;
    public const int NOT_FOUND = 3;

    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private function __construct(
        private readonly bool $json,
        private readonly SymfonyStyle $io,
        private readonly OutputInterface $stderr,
        private readonly FieldErrors $fields,
    ) {
    }

    public static function addOptions(Command $command): void
    {
        $command
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table (for people) or json (for scripts)', 'table')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Same as --format=json')
        ;
    }

    /**
     * @throws InvalidOptionException for a format other than table and json
     */
    public static function of(InputInterface $input, OutputInterface $output, FieldErrors $fields): self
    {
        $format = $input->getOption('json') ? 'json' : $input->getOption('format');
        if (!\in_array($format, ['table', 'json'], true)) {
            throw new InvalidOptionException('The --format option must be "table" or "json".');
        }

        return new self(
            'json' === $format,
            new SymfonyStyle($input, $output),
            $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output,
            $fields,
        );
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    /**
     * Runs the action and turns the three service exceptions into a message and an exit code.
     *
     * @param callable(): void $action
     */
    public function run(callable $action): int
    {
        try {
            $action();
        } catch (ValidationFailed $e) {
            return $this->fail($e->getMessage(), self::INVALID, $this->fields->options($e->errors));
        } catch (Refused $e) {
            return $this->fail($e->getMessage(), self::REFUSED);
        } catch (NotFound $e) {
            return $this->fail($e->getMessage(), self::NOT_FOUND);
        }

        return self::OK;
    }

    /**
     * Prints a failure and returns its exit code: JSON on stdout, or the message / one `--option: message` line per
     * field on stderr.
     *
     * @param array<string, string> $fields command-line spelling => message
     */
    public function fail(string $message, int $code, array $fields = []): int
    {
        if ($this->json) {
            $this->document(['error' => [] === $fields ? $message : 'Invalid values.', 'fields' => (object) $fields]);

            return $code;
        }
        if ([] === $fields) {
            $this->stderr->writeln($message, OutputInterface::OUTPUT_RAW);

            return $code;
        }
        foreach ($fields as $key => $text) {
            $this->stderr->writeln($key.': '.$text, OutputInterface::OUTPUT_RAW);
        }

        return $code;
    }

    /**
     * Whether a delete may go ahead: --yes, or a yes at the prompt of an interactive human run. JSON mode and
     * non-interactive runs never prompt.
     */
    public function confirmed(InputInterface $input, string $question): bool
    {
        if (true === $input->getOption('yes')) {
            return true;
        }
        if ($this->json || !$input->isInteractive()) {
            return false;
        }

        return $this->io->confirm($question, false);
    }

    /**
     * A warning about a change that was made: the work is done (exit code 0) but a follow-up step failed. In JSON mode
     * it becomes the document's `warning` key (stderr stays empty); otherwise it is one line on stderr.
     *
     * @param array<mixed> $payload
     *
     * @return array<mixed> the payload, with `warning` added in JSON mode when there is one
     */
    public function withWarning(array $payload, ?string $warning): array
    {
        if (null === $warning) {
            return $payload;
        }
        if ($this->json) {
            return $payload + ['warning' => $warning];
        }
        $this->stderr->writeln('Warning: '.$warning, OutputInterface::OUTPUT_RAW);

        return $payload;
    }

    /**
     * @param array<mixed>         $payload
     * @param callable(self): void $human   printed instead of the document in table mode
     */
    public function emit(array $payload, callable $human): void
    {
        if ($this->json) {
            $this->document($payload);

            return;
        }
        $human($this);
    }

    /**
     * @param array<mixed> $payload
     */
    public function document(array $payload): void
    {
        $this->io->writeln(json_encode($payload, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param list<string>       $headers
     * @param list<list<scalar>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->io->table($headers, array_map(
            static fn (array $row): array => array_map(static fn (mixed $cell): string => OutputFormatter::escape((string) $cell), $row),
            $rows,
        ));
    }

    /**
     * `key: value` lines; an array value prints one `  - item` line per item (or `-` when empty).
     *
     * @param array<string, scalar|list<scalar>|null> $pairs
     */
    public function pairs(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            if (\is_array($value)) {
                $this->io->writeln($key.':'.([] === $value ? ' -' : ''), OutputInterface::OUTPUT_RAW);
                foreach ($value as $item) {
                    $this->io->writeln('  - '.$item, OutputInterface::OUTPUT_RAW);
                }
                continue;
            }
            $this->io->writeln($key.': '.(null === $value || '' === $value ? '-' : (string) $value), OutputInterface::OUTPUT_RAW);
        }
    }

    public function success(string $message): void
    {
        $this->io->writeln(OutputFormatter::escape($message), OutputInterface::OUTPUT_NORMAL);
    }
}
