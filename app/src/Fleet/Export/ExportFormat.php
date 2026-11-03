<?php

declare(strict_types=1);

namespace App\Fleet\Export;

/**
 * The versioned project export file: UTF-8 JSON, one document, stable key order, no ids, timestamps or instance state.
 */
final class ExportFormat
{
    public const string NAME = 'fleet-project-export';
    public const int VERSION = 1;
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param array<mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * Reads a file of at most MAX_BYTES.
     *
     * @throws InvalidExport when the file is missing, unreadable or too large
     */
    public static function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw InvalidExport::of('The file does not exist or cannot be read.');
        }
        $size = @filesize($path);
        if (false === $size || $size > self::MAX_BYTES) {
            throw InvalidExport::of(\sprintf('The file is larger than %d MiB.', self::MAX_BYTES / 1024 / 1024));
        }
        $content = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (false === $content || \strlen($content) > self::MAX_BYTES) {
            throw InvalidExport::of('The file cannot be read within the size limit.');
        }

        return $content;
    }
}
