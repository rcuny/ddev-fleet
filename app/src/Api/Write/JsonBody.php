<?php

declare(strict_types=1);

namespace App\Api\Write;

use App\Api\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * The JSON object of a write request. An empty body is an empty object; anything else that is not a JSON object is
 * a 400. Fields are read through typed getters, so a wrong type is a 422 naming the field.
 */
final readonly class JsonBody
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    public static function of(Request $request): self
    {
        $raw = $request->getContent();
        if ('' === trim($raw)) {
            return new self([]);
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw ApiProblem::invalid('invalid_json', 'The body must be a JSON object.');
        }

        return new self($data);
    }

    public function string(string $field, bool $required = false): ?string
    {
        $value = $this->data[$field] ?? null;
        if (null === $value) {
            if ($required) {
                throw ApiProblem::validation([$field => 'This value is required.']);
            }

            return null;
        }
        if (!\is_string($value) || '' === trim($value)) {
            throw ApiProblem::validation([$field => 'Must be a non-empty string.']);
        }

        return $value;
    }

    public function bool(string $field): bool
    {
        $value = $this->data[$field] ?? false;
        if (!\is_bool($value)) {
            throw ApiProblem::validation([$field => 'Must be true or false.']);
        }

        return $value;
    }

    /**
     * @return array<mixed>|null
     */
    public function array(string $field): ?array
    {
        $value = $this->data[$field] ?? null;
        if (null !== $value && !\is_array($value)) {
            throw ApiProblem::validation([$field => 'Must be a JSON array or object.']);
        }

        return $value;
    }
}
