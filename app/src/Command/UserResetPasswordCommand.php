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
use App\Fleet\System\AutheliaConfigRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
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
#[AsCommand(
    name: 'fleet:user:reset-password',
    description: 'Set a user\'s password and refresh the Authelia users file',
    help: <<<'HELP'
        Asks for the new password twice, without echo, then stores its argon2id hash, rewrites the Authelia users
        file and has Authelia pick it up (<info>--no-apply</info> skips the last step).

        It needs a terminal. Over SSH force one with <info>ssh -t user@host ...</info>. Without one (a pipe, cron, plain
        <info>ssh host command</info>) it refuses to run rather than read the password from an argument or the
        environment, and it never falls back to a visible prompt.
        HELP,
)]
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

        try {
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
        } catch (RuntimeException $e) {
            $io->error('The password cannot be typed without echo here ('.$e->getMessage().'). Run this from a terminal, over SSH with `ssh -t`.');

            return Command::FAILURE;
        }

        // The hash and the users file change together: the transaction commits only after the file was rendered and
        // written, so a failure anywhere leaves both as they were.
        try {
            $changed = $this->users->transactional(function () use ($username, $password): bool {
                $this->userService->setPassword($username, $password);

                return $this->usersFile->sync($this->users);
            });
        } catch (UnknownUser|\InvalidArgumentException|\RuntimeException $e) {
            $io->error('Nothing was changed: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('The password of "%s" was changed.', $username));
        $io->writeln($changed ? 'The Authelia users file was updated.' : 'The Authelia users file was already up to date.');

        if ($input->getOption('no-apply')) {
            $io->note('Skipped: installing the users file and restarting Authelia (--no-apply). The new password only works after that.');
            $io->note(AutheliaConfigRenderer::banNotice($username));

            return Command::SUCCESS;
        }
        try {
            $this->applier->apply(ApplyTarget::Authelia);
        } catch (\RuntimeException $e) {
            $io->error('The password is stored, but the sign-in service was not updated: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln('Authelia was updated.');
        $io->note(AutheliaConfigRenderer::banNotice($username));

        return Command::SUCCESS;
    }

    /**
     * Hidden, never trimmed, never visible: if the terminal cannot hide the typing, asking fails instead of
     * falling back to an echoed prompt.
     */
    public static function passwordQuestion(string $label): Question
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        // Not trimmed: spaces at either end are part of a password. Depending on how the answer is read (tty or
        // piped), Symfony leaves the line break on it, and a line can never contain one, so that is cut by hand.
        $question->setTrimmable(false);
        $question->setMaxAttempts(1);

        return $question;
    }

    private function askHidden(SymfonyStyle $io, string $label): string
    {
        $answer = $io->askQuestion(self::passwordQuestion($label));

        return \is_string($answer) ? rtrim($answer, "\r\n") : '';
    }
}
