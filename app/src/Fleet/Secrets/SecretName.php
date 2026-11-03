<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * The names of managed secrets (the ones an administrator sets in the browser or on the command line): UPPER_CASE,
 * at most 64 characters, the way an environment variable is written. The placeholder that stands for a secret in an
 * asset file is the kebab-case of the name, as in 0.x: JIRA_CLAUDE_TOKEN is `[[jira-claude-token]]`.
 */
final class SecretName
{
    /** The tokens the deploy fills in itself; a secret may not shadow one of them. */
    public const array BUILT_IN_TOKENS = ['instance-id', 'project', 'instance', 'branch', 'fleet-domain', 'instance-fqdn', 'issue-id'];

    public static function error(mixed $name): ?string
    {
        if (!\is_string($name) || '' === $name) {
            return 'Enter a name.';
        }
        if (1 !== preg_match(SecretStore::MANAGED_NAME_PATTERN, $name)) {
            return 'Use capital letters, digits and underscores only, starting with a letter, at most 64 characters (for example JIRA_TOKEN).';
        }
        if (\in_array(self::token($name), self::BUILT_IN_TOKENS, true)) {
            return \sprintf('This name is taken by the built-in [[%s]] placeholder.', self::token($name));
        }

        return null;
    }

    /** The placeholder name (without brackets) of a secret name. */
    public static function token(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }

    /**
     * The secret name a placeholder name stands for, or null when no valid secret name maps to it.
     */
    public static function fromToken(string $token): ?string
    {
        $name = strtoupper(str_replace('-', '_', $token));

        return 1 === preg_match(SecretStore::MANAGED_NAME_PATTERN, $name) ? $name : null;
    }
}
