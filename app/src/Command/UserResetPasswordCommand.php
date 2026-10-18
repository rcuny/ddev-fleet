<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\User\UserRepository;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserService;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery path when nobody can sign in: sets a user's password from the host shell. The password is asked on the
 * terminal without echo and never taken from an argument or the environment (those show up in `ps` and shell history).
 * Authelia's own self-service reset is disabled, so this is the way to change a password until M0.10.
 */
#[AsCommand(name: 'fleet:user:reset-password', description: 'Set a user\'s password and refresh the Authelia users file')]
final class UserResetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $userService,
        private readonly PasswordPolicy $policy,
        private readonly AutheliaUsersFile $usersFile,
        private readonly Applier $applier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The user whose password is reset')
            ->addOption('no-apply', null, InputOption::VALUE_NONE, 'Write the Authelia users file but do not install it (no sudo, no Authelia restart)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        \assert(\is_string($username));

        if (!$input->isInteractive()) {
            $io->error('The password is asked on the terminal; run this command interactively.');

            return Command::FAILURE;
        }
        if (null === $this->users->findByUsername($username)) {
            $io->error(UnknownUser::named($username)->getMessage());

            return Command::FAILURE;
        }

        $password = $this->askHidden($io, 'New password');
        $violations = $this->policy->violations($password, $username);
        if ([] !== $violations) {
            $io->error($violations);

            return Command::FAILURE;
        }
        if ($password !== $this->askHidden($io, 'Repeat the new password')) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        try {
            $this->userService->setPassword($username, $password);
        } catch (UnknownUser|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            unset($password);
        }
        $io->success(\sprintf('The password of "%s" was changed.', $username));

        try {
            $changed = $this->usersFile->write($this->users->all());
            $io->writeln($changed ? 'The Authelia users file was updated.' : 'The Authelia users file was already up to date.');
            if ($input->getOption('no-apply')) {
                $io->note('Skipped: installing the users file and restarting Authelia (--no-apply). The new password only works after that.');

                return Command::SUCCESS;
            }
            $this->applier->apply(ApplyTarget::Authelia);
        } catch (\RuntimeException $e) {
            $io->error('The password is stored, but the sign-in service was not updated: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln('Authelia was updated.');

        return Command::SUCCESS;
    }

    private function askHidden(SymfonyStyle $io, string $label): string
    {
        $question = new Question($label);
        $question->setHidden(true);
        // Not trimmed: spaces at either end are part of a password. Depending on how the answer is read (tty or
        // piped), Symfony leaves the line break on it, and a line can never contain one, so that is cut by hand.
        $question->setTrimmable(false);
        $question->setMaxAttempts(1);

        $answer = $io->askQuestion($question);

        return \is_string($answer) ? rtrim($answer, "\r\n") : '';
    }
}
