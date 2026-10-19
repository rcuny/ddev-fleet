<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * Substitutes `[[token]]` in a text from the context tokens (ContextTokens). A token that stays unresolved is an error, and a
 * substituted value is never scanned again, so a value can neither name another token nor split a word.
 */
final class TokenRenderer
{
    private const string TOKEN = '/\[\[([a-z0-9-]+)\]\]/';

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $tokens
     *
     * @throws \InvalidArgumentException naming the unresolved tokens (a token name is not secret)
     */
    public static function render(string $text, array $tokens): string
    {
        $missing = [];
        $out = preg_replace_callback(self::TOKEN, static function (array $m) use ($tokens, &$missing): string {
            if (!isset($tokens[$m[1]])) {
                $missing[$m[1]] = '[['.$m[1].']]';

                return $m[0];
            }

            return $tokens[$m[1]];
        }, $text);
        if (null === $out) {
            throw new \InvalidArgumentException('The text could not be searched for tokens.');
        }
        if ([] !== $missing) {
            ksort($missing);

            throw new \InvalidArgumentException('unresolved token(s): '.implode(', ', $missing));
        }

        return $out;
    }
}
