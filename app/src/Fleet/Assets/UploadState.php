<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * Where a chunked upload stands: what the browser needs to send the rest.
 */
final readonly class UploadState
{
    /**
     * @param list<int> $received the indexes of the chunks the server holds, ascending
     */
    public function __construct(
        public string $id,
        public string $project,
        public string $dir,
        public string $name,
        public int $size,
        public int $chunkSize,
        public int $chunks,
        public array $received,
    ) {
    }

    public function path(): string
    {
        return '' === $this->dir ? $this->name : $this->dir.'/'.$this->name;
    }

    public function isComplete(): bool
    {
        return \count($this->received) === $this->chunks;
    }

    /**
     * @return array{id: string, name: string, path: string, size: int, chunkSize: int, chunks: int, received: list<int>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path(),
            'size' => $this->size,
            'chunkSize' => $this->chunkSize,
            'chunks' => $this->chunks,
            'received' => $this->received,
        ];
    }
}
