<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * A regular file opened for reading, already checked not to be (or sit behind) a symbolic link. The caller closes the
 * handle.
 */
final readonly class AssetFile
{
    /**
     * @param resource $handle
     */
    public function __construct(
        public mixed $handle,
        public string $name,
        public int $size,
    ) {
    }
}
