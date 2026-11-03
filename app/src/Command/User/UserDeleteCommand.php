<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\JsonOutput;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\LastAdminGuard;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:user:delete', description: 'Delete a user with their project memberships (refused for the last enabled administrator)')]
final class UserDeleteCommand extends AbstractUserCommand
{
    public function __construct(
        private readonly UserRepository $repository,
        private readonly UserService $users,
        private readonly IdentitySync $sync,
        private readonly LastAdminGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Username');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = self::output($input, $output);

        return self::guarded($out, function () use ($input, $out): void {
            $username = (string) $input->getArgument('username');
            $user = $this->repository->findByUsername($username) ?? throw UnknownUser::named($username);
            $this->guard->assertMayDelete($user);
            if (!$out->confirmed($input, \sprintf('Delete user "%s"?', $username))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }

            [, $warning] = self::commit($this->sync, function () use ($username): void {
                $user = $this->repository->findByUsername($username) ?? throw UnknownUser::named($username);
                $this->guard->assertMayDelete($user);
                $this->users->delete($username);
            });
            $out->emit($out->withWarning(['deleted' => $username], $warning), static function (JsonOutput $out) use ($username): void {
                $out->success(\sprintf('User "%s" deleted.', $username));
            });
        });
    }
}
