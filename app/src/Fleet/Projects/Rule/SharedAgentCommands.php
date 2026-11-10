<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

use App\Domain\Template\Template;

/**
 * Warns about command lines that load SSH keys into DDEV's host-wide ssh-agent (FLE-266): `ddev auth ssh` and
 * `ssh-add -D` (which also empties that agent). That agent is shared by every DDEV project on the host, so one template
 * line would hand a key to all instances. A warning, never a refusal: the owner may still want it on a box of their own.
 */
final class SharedAgentCommands
{
    public const string ADVICE = 'it loads or clears keys in DDEV\'s shared ssh-agent for every instance on the host; turn on "git over SSH in the container" and use extra SSH keys instead (docs/ssh-keys.md).';

    private const string AUTH = '/\bddev\s+auth\s+ssh\b/';
    private const string CLEAR = '/\bssh-add\b[^\n]*?\s-[A-Za-z]*D/';

    /**
     * @param list<string> $lines the command lines of one list
     *
     * @return list<int> the 1-based numbers of the offending lines
     */
    public static function lineNumbers(array $lines): array
    {
        $found = [];
        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::AUTH, $line) || 1 === preg_match(self::CLEAR, $line)) {
                $found[] = $index + 1;
            }
        }

        return $found;
    }

    /**
     * @param array<string, list<string>> $lists command lists by label, for example ['post-deploy' => [...], 'tty1' => [...]]
     *
     * @return list<string> one sentence per list with an offending line
     */
    public static function warnings(array $lists): array
    {
        $warnings = [];
        foreach ($lists as $label => $lines) {
            $numbers = self::lineNumbers($lines);
            if ([] !== $numbers) {
                $warnings[] = \sprintf('%s line %s runs `ddev auth ssh` or `ssh-add -D`: %s', $label, implode(', ', $numbers), self::ADVICE);
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    public static function forTemplate(Template $template): array
    {
        return self::warnings(['post-deploy' => $template->postDeploy(), 'tty1' => $template->tty1(), 'tty2' => $template->tty2()]);
    }
}
