<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A small checker for the subset of JSON Schema the OpenAPI document uses (`$ref`, `allOf`, `type` as a string or a
 * list, `enum`, `required`, `properties`, `additionalProperties`, `items`). Anything else is ignored on purpose; the
 * contract test only needs to catch a field that went missing, changed type or left its enum.
 */
final class OpenApiSchema
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(private readonly array $document)
    {
    }

    /**
     * @param array<string, mixed> $node a node that may be a `$ref`
     *
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        $guard = 0;
        while (isset($node['$ref']) && \is_string($node['$ref']) && $guard++ < 20) {
            $target = $this->document;
            foreach (explode('/', ltrim($node['$ref'], '#/')) as $part) {
                $target = $target[$part] ?? throw new \LogicException('Unresolved $ref '.$node['$ref']);
            }
            $node = $target;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> the violations, empty when the value fits
     */
    public function violations(mixed $value, array $schema, string $path = '$'): array
    {
        $schema = $this->resolve($schema);
        $out = [];
        foreach ($schema['allOf'] ?? [] as $part) {
            array_push($out, ...$this->violations($value, $part, $path));
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if ([] === array_filter($types, fn (string $t): bool => $this->is($value, $t))) {
                return [\sprintf('%s: expected %s, got %s', $path, implode('|', $types), get_debug_type($value))];
            }
        }
        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $out[] = \sprintf('%s: %s is not one of %s', $path, json_encode($value), json_encode($schema['enum']));
        }
        if (\is_array($value) && (!array_is_list($value) || [] === $value && isset($schema['properties']))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!\array_key_exists($key, $value)) {
                    $out[] = \sprintf('%s: missing "%s"', $path, $key);
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    array_push($out, ...$this->violations($item, $schema['properties'][$key], $path.'.'.$key));
                } elseif (isset($schema['additionalProperties']) && \is_array($schema['additionalProperties'])) {
                    array_push($out, ...$this->violations($item, $schema['additionalProperties'], $path.'.'.$key));
                }
            }
        } elseif (\is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                array_push($out, ...$this->violations($item, $schema['items'], $path.'['.$i.']'));
            }
        }

        return $out;
    }

    private function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => null === $value,
            'array' => \is_array($value) && array_is_list($value),
            'object' => \is_array($value) && (!array_is_list($value) || [] === $value),
            default => true,
        };
    }
}
