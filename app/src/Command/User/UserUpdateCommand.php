<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\JsonOutput;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\LastAdminGuard;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'fleet:user:update',
    description: 'Change a user (only the options you give)',
    help: <<<'HELP'
        Changes the display name, the email, whether the user is enabled and whether the user is an administrator. A
        project membership is changed with <info>fleet user:grant</info> and <info>fleet user:revoke</info>, a password
        with <info>fleet user:reset-password</info>.

        <info>--site-only</info> makes the user a site-only user (opens the instance sites of its projects, nothing else;
        an administrator cannot be one), <info>--no-site-only</info> a regular user again (an email is needed, and
        the stored password is kept: set a new one with <info>fleet user:reset-password</info>).

        The last enabled administrator cannot be disabled or demoted (exit code 2).
        HELP,
)]
final class UserUpdateCommand extends AbstractUserCommand
{
    public function __construct(
        private readonly UserRepository $repository,
        private readonly UserService $users,
        private readonly ProjectAccessService $access,
        private readonly IdentitySync $sync,
        private readonly LastAdminGuard $guard,
        private readonly AdminGroup $admins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'New display name')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'New email address')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the user (the user can sign in)')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the user (the user cannot sign in; nothing is deleted)')
            ->addOption('admin', null, InputOption::VALUE_NEGATABLE, 'Make the user an administrator (--no-admin: a member)')
            ->addOption('site-only', null, InputOption::VALUE_NEGATABLE, 'Make the user a site-only user (--no-site-only: a regular user)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = self::output($input, $output);

        return self::guarded($out, function () use ($input, $out): void {
            $username = (string) $input->getArgument('username');
            $displayName = $input->getOption('display-name');
            $email = $input->getOption('email');
            $enable = true === $input->getOption('enable');
            $disable = true === $input->getOption('disable');
            $admin = $input->getOption('admin');
            $siteOnly = $input->getOption('site-only');
            if ($enable && $disable) {
                throw new \InvalidArgumentException('--enable and --disable cannot be used together.');
            }
            if (null === $displayName && null === $email && !$enable && !$disable && null === $admin && null === $siteOnly) {
                throw new \InvalidArgumentException('Nothing to change: give --display-name, --email, --enable, --disable or --admin / --no-admin or --site-only / --no-site-only.');
            }
            $displayName = \is_string($displayName) ? $displayName : null;
            $email = \is_string($email) ? $email : null;
            if (null === $this->repository->findByUsername($username)) {
                throw UnknownUser::named($username);
            }

            [$user, $warning] = self::commit($this->sync, function () use ($username, $displayName, $email, $enable, $disable, $admin, $siteOnly): User {
                $user = $this->repository->findByUsername($username) ?? throw UnknownUser::named($username);
                // The guard runs before each change, so the second one sees the first.
                if ($disable) {
                    $this->guard->assertMayDisable($user);
                }
                if (false === $admin) {
                    $this->guard->assertMayDemote($user);
                }
                if (\is_bool($siteOnly)) {
                    $this->users->setSiteOnly($username, $siteOnly);
                }
                if (null !== $displayName || null !== $email || \is_bool($siteOnly)) {
                    $this->users->updateProfile($username, $displayName, $email);
                }
                if ($enable || $disable) {
                    $this->users->setEnabled($username, $enable);
                }
                if (\is_bool($admin)) {
                    $this->users->setAdmin($username, $admin);
                }

                return $user;
            });
            $snapshot = UserSnapshot::of($user, $this->admins->includes($user), $this->access->projectsOf($username));
            $out->emit($out->withWarning($snapshot, $warning), static function (JsonOutput $out) use ($username): void {
                $out->success(\sprintf('User "%s" updated.', $username));
            });
        });
    }
}
