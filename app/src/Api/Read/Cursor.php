<?php

declare(strict_types=1);

namespace App\Api\Read;

use App\Api\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `?limit=` (default 50, max 200) and the opaque `?cursor=` every list takes (design 6.2). The cursor is a position
 * (an offset, or the last job id) hidden in base64url, so clients treat it as a token and the position can change.
 */
final readonly class Cursor
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(public int $limit, public int $position)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $limit = $request->query->get('limit');
        if (null === $limit || '' === $limit) {
            $size = self::DEFAULT_LIMIT;
        } elseif (ctype_digit($limit) && (int) $limit >= 1 && (int) $limit <= self::MAX_LIMIT) {
            $size = (int) $limit;
        } else {
            throw ApiProblem::invalid('invalid_limit', \sprintf('"limit" must be a number from 1 to %d.', self::MAX_LIMIT));
        }

        $cursor = $request->query->get('cursor');
        if (null === $cursor || '' === $cursor) {
            return new self($size, 0);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $decoded || 1 !== preg_match('/^c:(\d{1,12})$/', $decoded, $m)) {
            throw ApiProblem::invalid('invalid_cursor', 'The cursor is not one this API issued.');
        }

        return new self($size, (int) $m[1]);
    }

    public static function encode(int $position): string
    {
        return rtrim(strtr(base64_encode('c:'.$position), '+/', '-_'), '=');
    }

    /**
     * Slices an in-memory list by offset.
     *
     * @template T
     *
     * @param list<T> $rows
     *
     * @return array{items: list<T>, next_cursor: ?string}
     */
    public function slice(array $rows): array
    {
        $items = \array_slice($rows, $this->position, $this->limit);
        $end = $this->position + \count($items);

        return ['items' => $items, 'next_cursor' => $end < \count($rows) ? self::encode($end) : null];
    }
}
