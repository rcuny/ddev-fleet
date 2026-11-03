<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\JsonOutput;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\ProjectAccessService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:user:list', description: 'List the users')]
final class UserListCommand extends AbstractUserCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminGroup $admins,
        private readonly ProjectAccessService $access,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = self::output($input, $output);

        return self::guarded($out, function () use ($out): void {
            $rows = array_map(
                fn (User $user): array => UserSnapshot::of($user, $this->admins->includes($user), $this->access->projectsOf($user->username())),
                $this->users->all(),
            );
            $out->emit($rows, static fn (JsonOutput $out) => $out->table(
                ['USERNAME', 'NAME', 'EMAIL', 'ROLE', 'STATUS', 'PROJECTS'],
                array_map(static fn (array $row): array => [
                    $row['username'],
                    $row['display_name'],
                    '' === $row['email'] ? '-' : $row['email'],
                    $row['admin'] ? 'admin' : ($row['site_only'] ? 'site-only' : 'member'),
                    $row['enabled'] ? 'enabled' : 'disabled',
                    $row['admin'] ? 'all' : ([] === $row['projects'] ? '-' : implode(', ', $row['projects'])),
                ], $rows),
            ));
        });
    }
}
