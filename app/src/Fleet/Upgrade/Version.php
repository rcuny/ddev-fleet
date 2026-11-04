<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * A semantic version of the 1.x line: X.Y.Z with an optional pre-release suffix (v1.3.0-rc.1). Build metadata is not
 * accepted. Compared by SemVer precedence: a pre-release sorts before its release.
 */
final readonly class Version
{
    private const string PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?$/D';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $preRelease,
    ) {
    }

    public static function tryParse(string $text): ?self
    {
        if (1 !== preg_match(self::PATTERN, trim($text), $m)) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], '' === ($m[4] ?? '') ? null : $m[4]);
    }

    public static function parse(string $text): self
    {
        return self::tryParse($text) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a version (X.Y.Z)', $text));
    }

    public function isPreRelease(): bool
    {
        return null !== $this->preRelease;
    }

    /** The tag form: v1.2.3 or v1.2.3-rc.1. */
    public function tag(): string
    {
        return 'v'.$this;
    }

    public function compare(self $other): int
    {
        $byNumbers = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if (0 !== $byNumbers) {
            return $byNumbers;
        }
        if ($this->preRelease === $other->preRelease) {
            return 0;
        }
        if (null === $this->preRelease) {
            return 1;
        }
        if (null === $other->preRelease) {
            return -1;
        }

        return self::comparePreRelease($this->preRelease, $other->preRelease);
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function __toString(): string
    {
        return \sprintf('%d.%d.%d%s', $this->major, $this->minor, $this->patch, null === $this->preRelease ? '' : '-'.$this->preRelease);
    }

    private static function comparePreRelease(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);
        foreach ($left as $i => $part) {
            if (!isset($right[$i])) {
                return 1;
            }
            $other = $right[$i];
            $partNumeric = ctype_digit($part);
            $otherNumeric = ctype_digit($other);
            $result = match (true) {
                $partNumeric && $otherNumeric => (int) $part <=> (int) $other,
                $partNumeric => -1,
                $otherNumeric => 1,
                default => strcmp($part, $other) <=> 0,
            };
            if (0 !== $result) {
                return $result;
            }
        }

        return \count($left) <=> \count($right);
    }
}
