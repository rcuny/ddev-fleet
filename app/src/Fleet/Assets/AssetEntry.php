<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * One thing found in a project's assets directory. The name is made fit to print (invalid UTF-8 and control
 * characters replaced); `usable` says whether the browser and the commands may address it by that name: a file copied
 * in over SSH can have a name AssetPath refuses, and is then listed but cannot be downloaded or deleted here.
 */
final readonly class AssetEntry
{
    public const string DIRECTORY = 'dir';
    public const string FILE = 'file';
    public const string LINK = 'link';

    /**
     * @param string   $path     relative to the project's assets directory; empty when not usable
     * @param string   $kind     one of the constants above
     * @param int|null $size     bytes of a file; null for a folder or a link
     * @param int      $modified Unix time
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $kind,
        public ?int $size,
        public int $modified,
        public bool $usable,
    ) {
    }

    public function isDirectory(): bool
    {
        return self::DIRECTORY === $this->kind;
    }

    public function isFile(): bool
    {
        return self::FILE === $this->kind;
    }

    /**
     * @return array{name: string, path: string, type: string, size: int|null, modified: string, usable: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'type' => $this->kind,
            'size' => $this->size,
            'modified' => new \DateTimeImmutable('@'.$this->modified)->format('Y-m-d\TH:i:s\Z'),
            'usable' => $this->usable,
        ];
    }
}
