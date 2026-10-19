<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\UserResetPasswordCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Where a password may come from: a hidden prompt on a terminal (asked twice), or one line on standard input
 * (`--password-stdin`). Never an argument or an option, never the environment: those show up in `ps` and shell history.
 */
final class PasswordInput
{
    public static function addOption(Command $command): void
    {
        $command->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of standard input (otherwise it is asked on the terminal, without echo)');
    }

    /**
     * @throws \InvalidArgumentException when no password can be read or the two entries differ (policy violations are
     *                                   the service's to report)
     */
    public static function read(InputInterface $input, OutputInterface $output): string
    {
        if (true === $input->getOption('password-stdin')) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $line = fgets($stream ?? \STDIN, 1025);
            if (false === $line || '' === rtrim($line, "\r\n")) {
                throw new \InvalidArgumentException('No password was given on standard input.');
            }

            // A password is not trimmed (spaces at either end are part of it); only the line break goes.
            return rtrim($line, "\r\n");
        }
        if (!$input->isInteractive()) {
            throw new \InvalidArgumentException('The password is asked on the terminal: run this interactively, or pass --password-stdin and give it on standard input.');
        }

        $io = new SymfonyStyle($input, $output);
        try {
            $password = self::ask($io, 'Password');
            if ($password !== self::ask($io, 'Repeat the password')) {
                throw new \InvalidArgumentException('The two passwords differ.');
            }
        } catch (RuntimeException $e) {
            throw new \InvalidArgumentException('The password cannot be typed without echo here ('.$e->getMessage().'). Use a terminal (over SSH: `ssh -t`) or --password-stdin.', 0, $e);
        }

        return $password;
    }

    private static function ask(SymfonyStyle $io, string $label): string
    {
        $answer = $io->askQuestion(UserResetPasswordCommand::passwordQuestion($label));

        return \is_string($answer) ? rtrim($answer, "\r\n") : '';
    }
}
