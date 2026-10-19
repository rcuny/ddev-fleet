<?php

declare(strict_types=1);

namespace App\Command\Template;

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
    ];

    public static function configure(Command $command, bool $update): void
    {
        $command
            ->addOption('drupal-env', null, InputOption::VALUE_REQUIRED, 'Value of DRUPAL_ENV written to the instance .env')
            ->addOption('post-deploy', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command run inside the web container with `ddev exec` after a deploy (one line; repeat for several; the given list replaces the old one)')
            ->addOption('tty1', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command line typed into the first instance terminal (repeat for several; replaces the old list)')
            ->addOption('tty2', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command line typed into the second instance terminal (repeat for several; replaces the old list)')
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
