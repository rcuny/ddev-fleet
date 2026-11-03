<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** One published release: an annotated tag and the commit it points at. */
final readonly class Release
{
    public function __construct(public Version $version, public string $sha)
    {
    }

    public function tag(): string
    {
        return $this->version->tag();
    }

    /**
     * @return array{tag: string, sha: string}
     */
    public function toArray(): array
    {
        return ['tag' => $this->tag(), 'sha' => $this->sha];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $version = \is_string($data['tag'] ?? null) ? Version::tryParse($data['tag']) : null;
        $sha = $data['sha'] ?? null;
        if (null === $version || !\is_string($sha) || 1 !== preg_match('/^[0-9a-f]{40}$/D', $sha)) {
            return null;
        }

        return new self($version, $sha);
    }
}
