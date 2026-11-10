<?php

declare(strict_types=1);

namespace App\Fleet\Docs;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Renders docs/reference/01-cli.md from the live console definition. The output is deterministic (fixed group order, commands
 * sorted by name, no version, no date), so a repo test can compare the committed page with a fresh rendering and fail with
 * the command that regenerates it. Hand-written text lives in the constants below; everything per command comes from the
 * Application.
 */
final class CliReference
{
    /** Groups in the order of the page; any other group is appended alphabetically (`dev` and `docs` are never listed). */
    private const array GROUPS = [
        'project' => 'Git repositories Fleet can deploy.',
        'template' => 'Deploy recipes of a project.',
        'port' => 'The named-port catalogue.',
        'ssh' => 'Trusted git servers.',
        'instance' => 'Deploy, start, stop and watch instances.',
        'job' => 'The job queue.',
        'asset' => 'Per-project seed files.',
        'secret' => 'Encrypted secrets.',
        'user' => 'Users and roles.',
        'webhook' => 'Jira and Bitbucket receivers.',
        'schedule' => 'Cron schedules that redeploy.',
        'claude' => 'The Claude Code token.',
        'tmux' => 'The tmux workspace.',
        'workspace' => 'Workspace checks.',
        'host' => 'Host settings, certificates, capacity.',
        'backup' => 'Database backups.',
        'setup' => 'The setup wizard.',
        'export' => 'Export a project.',
        'import' => 'Import a project.',
        'upgrade' => 'Release check, history and verification.',
    ];

    private const array HIDDEN_GROUPS = ['dev', 'docs'];

    /** Helpers the worker or the upgrade helper runs; listed, but marked. */
    private const array INTERNAL = ['job:work', 'upgrade:backup', 'upgrade:plan', 'upgrade:prepare', 'upgrade:refresh', 'upgrade:restore'];

    /** Commands that need root (run them with sudo); the console cannot tell, so the list is kept here. */
    private const array SUDO = ['backup:restore', 'host:render'];

    private const array GLOBAL_OPTIONS = ['help', 'silent', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'no-debug', 'profile'];

    /** Regenerate with this command (named in the failure message of CliReferenceTest). */
    public const string REGENERATE = 'composer run docs:cli';

    public function render(Application $application): string
    {
        $byGroup = [];
        foreach ($application->all() as $name => $command) {
            if (!str_starts_with($name, 'fleet:') || $name !== $command->getName()) {
                continue;
            }
            $short = substr($name, \strlen('fleet:'));
            if ($command->isHidden() && !\in_array($short, self::INTERNAL, true)) {
                continue;
            }
            $group = explode(':', $short)[0];
            if (\in_array($group, self::HIDDEN_GROUPS, true)) {
                continue;
            }
            $byGroup[$group][$short] = $command;
        }

        $groups = [];
        foreach (array_keys(self::GROUPS) as $group) {
            if (isset($byGroup[$group])) {
                $groups[] = $group;
            }
        }
        $others = array_values(array_diff(array_keys($byGroup), array_keys(self::GROUPS)));
        sort($others);
        array_push($groups, ...$others);

        $count = 0;
        foreach ($byGroup as $commands) {
            $count += \count($commands);
        }

        $out = [];
        $out[] = "# CLI reference\n";
        $out[] = 'Every public `fleet` command ('.$count.'), with its arguments and options. Run `fleet <group>:<verb>` on the host as an administrator; '
            .'`fleet x:y` is short for `fleet:x:y`, and `fleet <group> <verb>` works too. Commands that need root are marked **sudo**. '
            .'Add `--help` to any command for the live text. Symfony adds global options (`-h`, `-q`, `-v`, `-V`, `--ansi`, `--no-ansi`, `-n`, '
            ."`--env`, `--no-debug`, `--profile`, `--silent`), which the tables below leave out.\n";
        $out[] = "Most commands accept `--format=table|json` and `--json`. In JSON mode an error is printed as `{\"error\": \"...\", \"fields\": {...}}`.\n";
        $out[] = 'This page is **generated** from the console definition by `fleet:docs:cli`; a repo test fails when it is stale. '
            .'Regenerate it with `'.self::REGENERATE."`. Do not edit it by hand.\n";

        $out[] = "## Contents\n";
        $out[] = '- [Exit codes](#exit-codes)';
        foreach ($groups as $group) {
            $out[] = \sprintf('- [%s](#%s): %s', $group, $group, $this->groupIntro($group));
        }
        $out[] = "- [Wrapper commands](#wrapper-commands-installbinfleet)\n";

        $out[] = "## Exit codes\n";
        $out[] = "| Code | Meaning |\n|---|---|\n| `0` | Done. |\n| `1` | A value was refused, or wrong usage. |\n"
            ."| `2` | Not allowed now (for example a delete without `--yes`, or a state that forbids it). |\n| `3` | Not found. |\n";
        $out[] = "Exceptions:\n";
        $out[] = '- **instance** batch commands: `0` every job succeeded, `1` every job failed, `2` some jobs of the batch failed. '
            .'`Ctrl-C` on an attached command marks the job `interrupted` and exits `130`.';
        $out[] = '- **upgrade check** (`fleet upgrade --check`): `0` up to date or update available, `1` failed, `2` usage, `3` blocked (nothing changed), `4` offline.';
        $out[] = '- **upgrade** (`sudo fleet upgrade`): `0` done, `1` failed, `2` usage, `3` refused, `5` verification failed.';
        $out[] = '- **export/import**: `2` means not confirmed or the passphrase cannot be read; `3` project not found.';
        $out[] = "- **host:tls-status**: `0` ok, `1` warning, `2` error.\n";

        foreach ($groups as $group) {
            $out[] = '## '.$group."\n";
            $out[] = $this->groupIntro($group)."\n";
            $commands = $byGroup[$group];
            ksort($commands);
            foreach ($commands as $short => $command) {
                array_push($out, ...$this->command($short, $command));
            }
        }

        $out[] = "## Wrapper commands (`install/bin/fleet`)\n";
        $out[] = 'The `/usr/local/bin/fleet` wrapper switches to the `fleet` user, loads `/etc/ddev-fleet/fleet.env` and adds the following on top of the console commands above.'."\n";
        $out[] = "| Command | What the wrapper does |\n|---|---|";
        $out[] = '| `fleet` | Lists the `fleet:*` commands. |';
        $out[] = '| `fleet <group> <verb>` | Same as `fleet <group>:<verb>`; a full `fleet:<group>:<verb>` name also works. |';
        $out[] = '| `sudo fleet upgrade [--to vX.Y.Z] [--yes] [--force=<check-id>]` | **sudo.** Hands over to the root helper that runs the installer, with a backup and a verification. Refused (exit `3`) without root. Options: [upgrade reference](05-upgrade.md#upgrade). |';
        $out[] = '| `fleet upgrade --check [--to vX.Y.Z] [--json]` | Runs `fleet:upgrade:check` (read-only, no root). |';
        $out[] = '| `fleet upgrade history`, `fleet upgrade verify` | Run `fleet:upgrade:history` and `fleet:upgrade:verify`. |';
        $out[] = '| `fleet tmux [<instance-id>]` | Attaches your terminal to the tmux workspace, optionally on one instance\'s window (also `fleet tmux attach [<instance-id>]` and `fleet tmux:attach`). Needs a terminal (`ssh -t`) and refuses to nest inside tmux (exit `2`). |';
        $out[] = '';
        $out[] = "`fleet backup:*` and the other groups are ordinary console commands, documented above.\n";
        $out[] = "## Learn more\n";
        $out[] = "- [Configuration](02-configuration.md)\n- [Installer](04-installer.md)\n- [Files on disk](03-files-on-disk.md)";

        return implode("\n", $out)."\n";
    }

    private function groupIntro(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group).'.';
    }

    /**
     * @return list<string>
     */
    private function command(string $short, Command $command): array
    {
        $out = ['### fleet '.$short."\n"];
        if (\in_array($short, self::INTERNAL, true)) {
            $out[] = "*(internal, run by the worker/upgrade helper)*\n";
        }
        if (\in_array($short, self::SUDO, true)) {
            $out[] = "**sudo**: needs root.\n";
        }
        $out[] = rtrim($this->clean($command->getDescription()), '.').".\n";
        $usages = array_merge([$command->getSynopsis(false)], $command->getAliases(), $command->getUsages());
        $out[] = "```\n".implode("\n", array_map(static fn (string $u): string => (string) preg_replace('/^fleet:/', 'fleet ', $u), $usages))."\n```\n";

        $definition = $command->getDefinition();
        $arguments = $definition->getArguments();
        if ([] !== $arguments) {
            $rows = ["| Argument | Required | Description |\n|---|---|---|"];
            foreach ($arguments as $argument) {
                $name = $argument->getName().($argument->isArray() ? '...' : '');
                $rows[] = \sprintf('| `%s` | %s | %s |', $name, $argument->isRequired() ? 'yes' : 'no', $this->clean($argument->getDescription()));
            }
            $out[] = implode("\n", $rows)."\n";
        }

        $options = array_filter($definition->getOptions(), static fn (InputOption $o): bool => !\in_array($o->getName(), self::GLOBAL_OPTIONS, true));
        if ([] !== $options) {
            $rows = ["| Option | Shortcut | Description | Default |\n|---|---|---|---|"];
            foreach ($options as $option) {
                $rows[] = \sprintf(
                    '| %s | %s | %s | %s |',
                    $this->optionName($option),
                    null !== $option->getShortcut() ? '`-'.$option->getShortcut().'`' : '',
                    $this->clean($option->getDescription()),
                    $this->defaultCell($option),
                );
            }
            $out[] = implode("\n", $rows)."\n";
        }

        return $out;
    }

    private function optionName(InputOption $option): string
    {
        $name = '--'.$option->getName();
        if ($option->acceptValue()) {
            $name .= $option->isValueRequired() ? '=VALUE' : '[=VALUE]';
        }
        if ($option->isNegatable()) {
            $name .= ' / --no-'.$option->getName();
        }
        if ($option->isArray()) {
            $name .= ' (repeatable)';
        }

        return '`'.$name.'`';
    }

    private function defaultCell(InputOption $option): string
    {
        $default = $option->getDefault();
        if (null === $default || false === $default || [] === $default) {
            return '';
        }
        if (\is_array($default)) {
            $default = implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $default));
        }
        if (true === $default) {
            $default = 'true';
        }

        return '`'.str_replace('|', '\\|', (string) $default).'`';
    }

    private function clean(string $text): string
    {
        $text = (string) preg_replace('#</?[a-z=;]+>#', '', $text);

        return trim(str_replace(['|', "\n"], ['\\|', ' '], $text));
    }
}
