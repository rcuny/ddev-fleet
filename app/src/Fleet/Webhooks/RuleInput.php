<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * Turns the flat fields of a rule (the option names of `fleet:webhook:rule:add`: `on-status`, `label-prefix`, `reuse`...)
 * into the `match` and `params` maps WebhookService::addRule() validates. The command and the Integrations tab both read
 * their input through it, so the two cannot disagree on which field means what. A blank or missing field is absent.
 */
final class RuleInput
{
    /** field name => key under its 0.x name, for the conditions */
    private const array MATCH = ['on-status' => 'on_status', 'on-event' => 'on_event', 'repo' => 'repo', 'match-branch' => 'branch', 'state' => 'state', 'comment' => 'comment'];
    /** field name => key, for what the action needs */
    private const array PARAMS = ['template' => 'template', 'branch' => 'branch', 'label' => 'label', 'label-prefix' => 'label_prefix', 'strip-suffix' => 'strip_suffix', 'pattern' => 'pattern', 'ref' => 'ref'];

    /**
     * @return list<string> the names of the text fields
     */
    public static function fields(): array
    {
        return [...array_keys(self::MATCH), ...array_keys(self::PARAMS)];
    }

    /**
     * @param array<string, mixed> $fields field name => value (strings are kept as given, anything else is ignored); `reuse` is a flag
     *
     * @return array{array<string, string>, array<string, string>} [match, params]
     */
    public static function split(array $fields, bool $reuse): array
    {
        return [self::pick($fields, self::MATCH), self::pick($fields, self::PARAMS) + ($reuse ? ['reuse' => '1'] : [])];
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    private static function pick(array $fields, array $map): array
    {
        $values = [];
        foreach ($map as $field => $key) {
            if (isset($fields[$field]) && \is_string($fields[$field]) && '' !== $fields[$field]) {
                $values[$key] = $fields[$field];
            }
        }

        return $values;
    }
}
