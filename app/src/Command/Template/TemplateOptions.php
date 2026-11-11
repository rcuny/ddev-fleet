<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\Support\CapacityOption;
use App\Domain\Template\SshIdentities;
use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The options fleet:template:create and fleet:template:update share, and how they become TemplateInput changes. Only
 * the options the person gave produce a change.
 */
final class TemplateOptions
{
    /** --unset word => the TemplateInput property it empties and the empty value. */
    private const array UNSET = [
        'drupal-env' => ['drupalEnv', null],
        'post-deploy' => ['postDeploy', []],
        'tty1' => ['tty1', []],
        'tty2' => ['tty2', []],
        'ssh-keys' => ['sshKeys', []],
    ];

    public static function configure(Command $command, bool $update): void
    {
        $command
            ->addOption('drupal-env', null, InputOption::VALUE_REQUIRED, 'Value of DRUPAL_ENV written to the instance .env')
            ->addOption('post-deploy', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command run inside the web container with `ddev exec` after a deploy (one line; repeat for several; the given list replaces the old one)')
            ->addOption('tty1', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command line typed into the first instance terminal (repeat for several; replaces the old list)')
            ->addOption('tty2', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command line typed into the second instance terminal (repeat for several; replaces the old list)')
            ->addOption('ssh-key', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Name of an extra SSH key (the file ssh/<name>_ed25519 in the fleet home) loaded into the instance\'s ssh-agent; the project needs container_ssh (repeat for several; replaces the old list)')
            ->addOption('ssh-identities', null, InputOption::VALUE_REQUIRED, 'Which keys the instance\'s ssh-agent holds: push-and-extras (the host push key and the extra keys, the default) or extras-only (no push key)')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Whether instances have a database of their own: yes (default) or no (Fleet exports FLEET_HAS_DB=0 and a poisoned DB_HOST)')
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Whether an AI agent works in the instances: yes or no (default). The UI warns and FLEET_AGENT=1 is exported')
            ->addOption('cpu-threads', null, InputOption::VALUE_REQUIRED, 'CPU threads all instances of the template may use together, at most the project\'s: a number, or none to inherit the project\'s')
            ->addOption('slots', null, InputOption::VALUE_REQUIRED, 'Concurrent heavy runs of the template, at most the project\'s slots: a number, or none for unlimited')
        ;
        if ($update) {
            $command->addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Clear a value: '.implode(', ', array_keys(self::UNSET)).' (repeat for several)');
        }
    }

    /**
     * The TemplateInput properties the given options change. A command text is split like a textarea
     * (CommandLines::normalise: CRLF, blank lines), so `--post-deploy="$(cat commands.txt)"` works; what a command line
     * must not contain is refused by the service, with the line number.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationFailed for a command text over CommandLines::MAX_TEXT, an --unset word that does not exist, or an --unset of a value that is also set
     */
    public static function changes(InputInterface $input, bool $update): array
    {
        $changes = [];
        $errors = [];
        if (null !== $env = $input->getOption('drupal-env')) {
            $changes['drupalEnv'] = (string) $env;
        }
        foreach (['post-deploy' => 'postDeploy', 'tty1' => 'tty1', 'tty2' => 'tty2'] as $option => $property) {
            $texts = (array) $input->getOption($option);
            if ([] === $texts) {
                continue;
            }
            $lines = [];
            foreach ($texts as $text) {
                // the same size bound as the browser textarea, before the text is split
                if (null !== $error = CommandLines::textError((string) $text)) {
                    $errors[$property] = $error;
                    continue 2;
                }
                array_push($lines, ...CommandLines::normalise((string) $text));
            }
            $changes[$property] = $lines;
        }
        $keys = array_map(static fn (mixed $name): string => (string) $name, (array) $input->getOption('ssh-key'));
        if ([] !== $keys) {
            $changes['sshKeys'] = $keys;
        }
        if (null !== $identities = $input->getOption('ssh-identities')) {
            $parsed = SshIdentities::tryFrom(str_replace('-', '_', (string) $identities));
            if (null === $parsed) {
                $errors['sshIdentities'] = 'Choose push-and-extras or extras-only.';
            } else {
                $changes['sshIdentities'] = $parsed;
            }
        }
        foreach (['database' => 'database', 'agent' => 'agent'] as $option => $property) {
            if (null === $given = $input->getOption($option)) {
                continue;
            }
            $flag = filter_var((string) $given, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
            if (null === $flag) {
                $errors[$property] = 'Choose yes or no.';
            } else {
                $changes[$property] = $flag;
            }
        }
        $errors += CapacityOption::collect($input, $changes);
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
