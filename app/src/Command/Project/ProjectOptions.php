<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Domain\Project\GitBotMode;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The options fleet:project:create and fleet:project:update share, and how they become ProjectInput changes. Only the
 * options the person gave produce a change, so an update never touches the rest.
 */
final class ProjectOptions
{
    /** The --git-bot words and the git identity modes they stand for. */
    private const array GIT_BOT = ['inherit' => GitBotMode::Inherit, 'off' => GitBotMode::Disabled, 'custom' => GitBotMode::Override];

    /** --unset word => the ProjectInput property it empties and the empty value. */
    private const array UNSET = [
        'default-branch' => ['defaultBranch', null],
        'default-template' => ['defaultTemplate', null],
        'hostnames' => ['additionalHostnames', []],
        'ports' => ['ports', []],
        'issue-id-regexp' => ['issueIdRegexp', null],
        'display-submodule-branch' => ['displaySubmoduleBranch', null],
    ];

    /** --option => ProjectInput property, for the options that carry a text. */
    private const array TEXT = [
        'git' => 'git',
        'default-branch' => 'defaultBranch',
        'default-template' => 'defaultTemplate',
        'git-bot-name' => 'gitBotName',
        'git-bot-email' => 'gitBotEmail',
        'issue-id-regexp' => 'issueIdRegexp',
        'display-submodule-branch' => 'displaySubmoduleBranch',
    ];

    public static function configure(Command $command, bool $update): void
    {
        $command
            ->addOption('git', null, InputOption::VALUE_REQUIRED, $update ? 'Git URL (https://host/path.git, ssh://... or user@host:path)' : 'Git URL (required): https://host/path.git, ssh://... or user@host:path')
            ->addOption('default-branch', null, InputOption::VALUE_REQUIRED, 'Branch deployed when none is given')
            ->addOption('hostname', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional hostname label (repeat for several; the given list replaces the old one)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Named port to assign (repeat for several; see fleet:port:list; the given list replaces the old one)')
            ->addOption('git-bot', null, InputOption::VALUE_REQUIRED, 'Git identity of the instances: inherit (host default), off (none) or custom (see --git-bot-name and --git-bot-email)')
            ->addOption('git-bot-name', null, InputOption::VALUE_REQUIRED, 'Git identity name (with --git-bot=custom)')
            ->addOption('git-bot-email', null, InputOption::VALUE_REQUIRED, 'Git identity email (with --git-bot=custom)')
            ->addOption('issue-id-regexp', null, InputOption::VALUE_REQUIRED, 'Pattern that extracts an issue id from a branch name')
            ->addOption('display-submodule-branch', null, InputOption::VALUE_REQUIRED, 'Submodule path whose branch is shown next to the instance')
        ;
        if ($update) {
            $command
                ->addOption('default-template', null, InputOption::VALUE_REQUIRED, 'Template deployed when none is given (one of the project\'s templates)')
                ->addOption('typesense', null, InputOption::VALUE_NEGATABLE, 'Give the project a Typesense search service (--no-typesense removes it)')
                ->addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Clear a value: '.implode(', ', array_keys(self::UNSET)).' (repeat for several)')
            ;

            return;
        }
        $command->addOption('typesense', null, InputOption::VALUE_NONE, 'Give the project a Typesense search service');
    }

    /**
     * The ProjectInput properties the given options change.
     *
     * @param GitBotMode $current the project's stored git identity mode (update); the mode --git-bot-name and
     *                            --git-bot-email are checked against when --git-bot is not given
     *
     * @return array<string, mixed>
     *
     * @throws ValidationFailed for a --git-bot or --unset word that does not exist, an --unset of a value that is also
     *                          set, or a git identity name or email without the custom mode
     */
    public static function changes(InputInterface $input, bool $update, GitBotMode $current = GitBotMode::Inherit): array
    {
        $changes = [];
        foreach (self::TEXT as $option => $property) {
            $value = $input->hasOption($option) ? $input->getOption($option) : null;
            if (null !== $value) {
                $changes[$property] = (string) $value;
            }
        }
        foreach (['hostname' => 'additionalHostnames', 'port' => 'ports'] as $option => $property) {
            $values = array_map(strval(...), (array) $input->getOption($option));
            if ([] !== $values) {
                $changes[$property] = array_values($values);
            }
        }
        $errors = [];
        if (null !== $word = $input->getOption('git-bot')) {
            $mode = self::GIT_BOT[(string) $word] ?? null;
            if (null === $mode) {
                $errors['gitBotMode'] = 'Choose inherit, off or custom.';
            } else {
                $changes['gitBotMode'] = $mode;
            }
        }
        // the name and email are only stored for the custom mode: refuse them rather than drop them and report success
        if ([] === $errors && GitBotMode::Override !== ($changes['gitBotMode'] ?? $current)) {
            foreach (['gitBotName', 'gitBotEmail'] as $property) {
                if (isset($changes[$property])) {
                    $errors[$property] = 'Only used with --git-bot=custom.';
                }
            }
        }
        if (true === $input->getOption('typesense')) {
            $changes['typesense'] = true;
        } elseif ($update && false === $input->getOption('typesense')) {
            $changes['typesense'] = false;
        }
        if ($update) {
            $given = array_keys($changes);
            foreach ((array) $input->getOption('unset') as $word) {
                $target = self::UNSET[(string) $word] ?? null;
                if (null === $target) {
                    $errors['unset'] = 'Choose '.implode(', ', array_keys(self::UNSET)).'.';
                } elseif (\in_array($target[0], $given, true)) {
                    $errors['unset'] = \sprintf('--unset=%s cannot be combined with a new value for it.', $word);
                } else {
                    $changes[$target[0]] = $target[1];
                }
            }
        }
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        return $changes;
    }
}
