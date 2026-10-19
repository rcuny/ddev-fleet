<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * A finished upload: where the file is, how big it is and the sha256 of its bytes (computed while assembling).
 */
final readonly class UploadResult
{
    public function __construct(
        public string $path,
        public string $name,
        public int $size,
        public string $sha256,
    ) {
    }

    /**
     * @return array{path: string, name: string, size: int, sha256: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'name' => $this->name, 'size' => $this->size, 'sha256' => $this->sha256];
    }
}
