<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\JsonOutput;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\InvalidUserInput;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'fleet:user:create',
    description: 'Create a user',
    help: <<<'HELP'
        Creates a user, optionally an administrator (<info>--admin</info>) and optionally a member of projects
        (<info>--project</info>, repeatable), then rewrites the Authelia users file and has Authelia pick it up.

        A <info>--site-only</info> user only opens the instance sites of its projects (to keep crawlers and strangers
        out of a shared site): no Fleet access, no email needed, a password of at least 4 characters, never an
        administrator.

        The password is asked on the terminal, twice and without echo. In a script give it as the first line of standard
        input with <info>--password-stdin</info>: <info>printf '%s\n' "$PASSWORD" | fleet user:create alice --email=alice@example.com --password-stdin</info>.
        It is never an argument or an option.
        HELP,
)]
final class UserCreateCommand extends AbstractUserCommand
{
    public function __construct(
        private readonly UserService $users,
        private readonly ProjectAccessService $access,
        private readonly IdentitySync $sync,
        private readonly AdminGroup $admins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username (lower case letters, digits, dot, underscore or dash; cannot be changed later)')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name (default: the username)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Make the user an administrator')
            ->addOption('site-only', null, InputOption::VALUE_NONE, 'A site-only user: opens the instance sites of its projects, nothing else (no email needed, short password allowed)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A project the user is a member of (repeatable)');
        PasswordInput::addOption($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = self::output($input, $output);

        return self::guarded($out, function () use ($input, $output, $out): void {
            $username = (string) $input->getArgument('username');
            $email = $input->getOption('email');
            $siteOnly = true === $input->getOption('site-only');
            if (!\is_string($email)) {
                $email = '';
            }
            if ('' === $email && !$siteOnly) {
                throw new InvalidUserInput('email', 'An email address is required.');
            }
            $displayName = $input->getOption('display-name');
            $displayName = \is_string($displayName) && '' !== $displayName ? $displayName : $username;
            $projects = array_values(array_unique(array_map(strval(...), (array) $input->getOption('project'))));
            $admin = true === $input->getOption('admin');
            $password = PasswordInput::read($input, $output);

            // One transaction: an unknown project name undoes the user as well.
            [$user, $warning] = self::commit($this->sync, function () use ($username, $displayName, $email, $password, $admin, $siteOnly, $projects) {
                $user = $this->users->create($username, $displayName, $email, $password, $admin, $siteOnly);
                if ([] !== $projects) {
                    $this->access->setProjects($username, $projects);
                }

                return $user;
            });
            $snapshot = UserSnapshot::of($user, $this->admins->includes($user), $this->access->projectsOf($username));
            $out->emit($out->withWarning($snapshot, $warning), static function (JsonOutput $out) use ($username): void {
                $out->success(\sprintf('User "%s" created.', $username));
            });
        });
    }
}
