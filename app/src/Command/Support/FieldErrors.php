<?php

declare(strict_types=1);

namespace App\Command\Support;

/**
 * Turns the field names of a ValidationFailed (form field names: gitBotMode, postDeploy, ...) into what a person typed
 * on the command line: `--git-bot`, `--post-deploy`, or `<name>` for a field that is an argument.
 */
final readonly class FieldErrors
{
    /** The few fields whose option name is not just their kebab-case spelling. */
    private const array RENAMED = [
        'additionalHostnames' => 'hostname',
        'ports' => 'port',
        'gitBotMode' => 'git-bot',
    ];

    /**
     * @param list<string> $arguments the fields that are command arguments
     */
    public function __construct(private array $arguments)
    {
    }

    public static function project(): self
    {
        return new self(['name']);
    }

    public static function template(): self
    {
        return new self(['project', 'name']);
    }

    public static function secret(): self
    {
        return new self(['name', 'value']);
    }

    public static function port(): self
    {
        return new self(['name']);
    }

    public static function user(): self
    {
        return new self(['username']);
    }

    public static function asset(): self
    {
        return new self(['project', 'path']);
    }

    /**
     * The command-line spelling of a field: `--default-branch`, `<name>`.
     */
    public function key(string $field): string
    {
        $kebab = self::RENAMED[$field] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $field));

        return \in_array($field, $this->arguments, true) ? '<'.$kebab.'>' : '--'.$kebab;
    }

    /**
     * @param array<string, string> $errors field => message
     *
     * @return array<string, string> command-line spelling => message, in the same order
     */
    public function options(array $errors): array
    {
        $mapped = [];
        foreach ($errors as $field => $message) {
            $mapped[$this->key($field)] = $message;
        }

        return $mapped;
    }
}
