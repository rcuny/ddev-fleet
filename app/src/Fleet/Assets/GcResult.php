<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * What `fleet asset:gc` removed.
 */
final readonly class GcResult
{
    public function __construct(
        public int $uploads,
        public int $temporaryFiles,
        public int $bytes,
    ) {
    }

    /**
     * @return array{uploads: int, temporaryFiles: int, bytes: int}
     */
    public function toArray(): array
    {
        return ['uploads' => $this->uploads, 'temporaryFiles' => $this->temporaryFiles, 'bytes' => $this->bytes];
    }
}
