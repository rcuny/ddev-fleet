<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Blanks the value of every `...token=<value>` (any case) in a log record's message, context and extra before any
 * handler sees it. The setup link carries its credential in the query string, and Symfony's own RouterListener logs
 * the whole request URI (`Matched route`, context `request_uri`); with `fingers_crossed` that record is flushed to the
 * journal as soon as any error-level record is produced in the same request.
 *
 * Registered by autoconfiguration (MonologBundle tags every ProcessorInterface service `monolog.processor`): with no
 * channel or handler restriction it runs for every channel and every handler, in every environment. The prod wiring is
 * pinned by tests/Integration/ProdLogRedactionTest.
 */
final class TokenRedactingProcessor implements ProcessorInterface
{
    public const string REDACTED = '[redacted]';

    private const string PATTERN = '/\b([a-z0-9_.-]*token)(=|%3d)([^&\s"\'#<>]+)/i';
    private const int MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::string($record->message),
            context: self::walk($record->context, 0),
            extra: self::walk($record->extra, 0),
        );
    }

    public static function string(string $text): string
    {
        return str_contains(strtolower($text), 'token') ? (string) preg_replace(self::PATTERN, '$1$2'.self::REDACTED, $text) : $text;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function walk(array $values, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $values;
        }
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = self::string($value);
            } elseif (\is_array($value)) {
                $values[$key] = self::walk($value, $depth + 1);
            } elseif ($value instanceof \Throwable && self::leaks($value)) {
                // The formatter would print the exception's message verbatim: hand it a redacted description instead.
                $values[$key] = [
                    'class' => $value::class,
                    'message' => self::string($value->getMessage()),
                    'code' => $value->getCode(),
                    'file' => basename($value->getFile()).':'.$value->getLine(),
                ];
            }
        }

        return $values;
    }

    private static function leaks(\Throwable $e): bool
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if (self::string($current->getMessage()) !== $current->getMessage()) {
                return true;
            }
        }

        return false;
    }
}
