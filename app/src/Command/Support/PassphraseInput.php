<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Where an export passphrase comes from on the command line: the environment variable FLEET_EXPORT_PASSPHRASE, one
 * line of standard input (`--passphrase-stdin`) or a hidden prompt. Never an option or an argument: argv is visible
 * to every user of the host.
 */
final class PassphraseInput
{
    public const string ENV = 'FLEET_EXPORT_PASSPHRASE';
    public const string OPTION = 'passphrase-stdin';

    /**
     * @param bool $confirm ask twice at a prompt (an export: a typo would seal the secrets with a passphrase nobody knows)
     * @param bool $skip    an empty answer at the prompt means "no passphrase" (an import may leave the secrets out)
     *
     * @return string|null null: no passphrase was given and none was required
     *
     * @throws Refused when a passphrase is required and cannot be read, or the two answers differ
     */
    public static function read(InputInterface $input, OutputInterface $output, bool $confirm, bool $skip, bool $required = true): ?string
    {
        if (true === $input->getOption(self::OPTION)) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $line = fgets($stream ?? \STDIN, 2048);

            return false === $line || '' === rtrim($line, "\r\n") ? self::none($required) : rtrim($line, "\r\n");
        }
        $env = getenv(self::ENV);
        if (\is_string($env) && '' !== $env) {
            return $env;
        }
        if (!$input->isInteractive()) {
            return self::none($required);
        }

        $io = new SymfonyStyle($input, $output);
        $first = $io->askHidden($skip ? 'Passphrase of the sealed secrets (empty: leave the secrets out)' : 'Passphrase (input is hidden)');
        if (!\is_string($first) || '' === $first) {
            return $skip ? null : self::none($required);
        }
        if ($confirm && $io->askHidden('Repeat the passphrase') !== $first) {
            throw new Refused('The two passphrases differ.');
        }

        return $first;
    }

    private static function none(bool $required): null
    {
        if ($required) {
            throw new Refused(\sprintf('A passphrase is needed: set %s, use --%s, or run the command in a terminal.', self::ENV, self::OPTION));
        }

        return null;
    }
}
