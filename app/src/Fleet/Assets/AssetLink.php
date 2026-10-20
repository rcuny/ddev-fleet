<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * What a symbolic link inside a project's assets points at, and whether deploy seeding honours it. Built by
 * AssetLinkResolver: `target` is the final regular file, relative to the project's assets directory, when the link is
 * honoured; `refusal` is the reason it is not.
 */
final readonly class AssetLink
{
    private function __construct(
        public ?string $target,
        public ?string $refusal,
        public string $raw,
    ) {
    }

    public static function allowed(string $target, string $raw): self
    {
        return new self($target, null, $raw);
    }

    public static function refused(string $refusal, string $raw): self
    {
        return new self(null, $refusal, $raw);
    }

    public function isAllowed(): bool
    {
        return null !== $this->target;
    }
}
