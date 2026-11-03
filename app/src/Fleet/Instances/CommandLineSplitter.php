<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * Splits a post-deploy line into argv words. There is no shell: a word ends at unquoted white space, `'...'` is literal,
 * `"..."` honours a backslash before `"`, `\` `$` and a backtick, and a backslash outside quotes escapes the next
 * character. Anything a shell would act on (`| & ; < > $ ` ( ) { }` unquoted) is refused, and the message points to
 * `ddev exec '...'` for a command that needs a shell inside the container.
 */
final class CommandLineSplitter
{
    private const string SHELL_CHARACTERS = '|&;<>$`(){}';

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException with a user-safe message (it never contains the line)
     */
    public static function split(string $line): array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $quote = null;
        $length = \strlen($line);

        for ($i = 0; $i < $length; ++$i) {
            $c = $line[$i];

            if ("'" === $quote) {
                if ("'" === $c) {
                    $quote = null;
                } else {
                    $word .= $c;
                }
            } elseif ('"' === $quote) {
                if ('"' === $c) {
                    $quote = null;
                } elseif ('\\' === $c && $i + 1 < $length && str_contains('"\\$`', $line[$i + 1])) {
                    $word .= $line[++$i];
                } else {
                    $word .= $c;
                }
            } elseif ("'" === $c || '"' === $c) {
                $quote = $c;
                $inWord = true;
            } elseif ('\\' === $c) {
                if ($i + 1 >= $length) {
                    throw new \InvalidArgumentException('The line ends with a backslash.');
                }
                $word .= $line[++$i];
                $inWord = true;
            } elseif (str_contains(" \t\r\n", $c)) {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }
            } elseif (str_contains(self::SHELL_CHARACTERS, $c)) {
                throw new \InvalidArgumentException(\sprintf('Shell syntax ("%s") is not supported: the line runs without a shell. To run something inside the container, use ddev exec \'...\'.', $c));
            } else {
                $word .= $c;
                $inWord = true;
            }
        }

        if (null !== $quote) {
            throw new \InvalidArgumentException('The line has an unbalanced quote.');
        }
        if ($inWord) {
            $words[] = $word;
        }
        if ([] === $words) {
            throw new \InvalidArgumentException('The line is empty.');
        }

        return $words;
    }
}
