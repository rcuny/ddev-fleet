<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * Replaces `[[token]]` placeholders in a text (an asset file, a tty command) with values.
 *
 * - A placeholder is `[[` + lowercase letters, digits and dashes + `]]`. The secret behind `[[jira-claude-token]]` is
 *   JIRA_CLAUDE_TOKEN: a project secret wins over a fleet-wide one of the same name.
 * - Built-in values (the caller's `instance-id`, `branch`, ...) win over any secret, and no secret may be named like
 *   one (SecretName).
 * - One pass over the original text: an inserted value is never scanned again, so a value that itself contains
 *   `[[x]]` is left alone. Values go in verbatim, with no quoting or escaping added (the 0.x quoting trap).
 * - Placeholders that stay unresolved are reported (render) or refused (renderStrict); the text keeps them as written.
 *
 * The deploy calls this (M0.04); nothing here touches the filesystem.
 */
final readonly class TokenRenderer
{
    public const string PATTERN = '/\[\[([a-z0-9-]+)\]\]/';

    public function __construct(private SecretStore $store)
    {
    }

    /**
     * @param string|null           $project the project whose secrets apply, or null for the fleet-wide ones only
     * @param array<string, string> $builtIn built-in placeholder name => value
     *
     * @throws SecretOpenFailed when a secret the text uses cannot be opened
     */
    public function render(string $text, ?string $project, array $builtIn = []): TokenRendering
    {
        preg_match_all(self::PATTERN, $text, $found);
        $values = [];
        $unresolved = [];
        $used = [];
        foreach (array_unique($found[1]) as $token) {
            if (\array_key_exists($token, $builtIn)) {
                $values[$token] = $builtIn[$token];
                continue;
            }
            $name = SecretName::fromToken($token);
            $value = null === $name ? null : $this->lookup($project, $name);
            if (null === $name || null === $value) {
                $unresolved[] = $token;
                continue;
            }
            $values[$token] = $value;
            $used[] = $name;
        }
        sort($unresolved);
        sort($used);

        $rendered = preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[$match[1]] ?? $match[0],
            $text,
        );

        return new TokenRendering($rendered ?? $text, $unresolved, $used);
    }

    /**
     * @param array<string, string> $builtIn
     *
     * @throws UnresolvedTokens when any placeholder cannot be resolved (nothing is returned)
     * @throws SecretOpenFailed when a secret the text uses cannot be opened
     */
    public function renderStrict(string $text, ?string $project, array $builtIn = []): string
    {
        $rendering = $this->render($text, $project, $builtIn);
        if (!$rendering->isComplete()) {
            throw new UnresolvedTokens($rendering->unresolved);
        }

        return $rendering->text;
    }

    private function lookup(?string $project, string $name): ?string
    {
        if (null !== $project) {
            $value = $this->store->getManaged($project, $name);
            if (null !== $value) {
                return $value;
            }
        }

        return $this->store->getManaged(null, $name);
    }
}
