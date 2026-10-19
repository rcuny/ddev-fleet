<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\JsonOutput;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\ProjectAccessService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'fleet:user:revoke',
    description: 'Remove the membership of a user in a project',
    help: <<<'HELP'
        Removes the membership and has Authelia pick up the access rules. It is safe to repeat: when nothing changed it
        says so and still exits 0. Administrators reach every project and need no membership.
        HELP,
)]
final class UserRevokeCommand extends AbstractUserCommand
{
    public function __construct(
        private readonly ProjectAccessService $access,
        private readonly IdentitySync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('project', InputArgument::REQUIRED, 'Project name');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = self::output($input, $output);

        return self::guarded($out, function () use ($input, $out): void {
            $username = (string) $input->getArgument('username');
            $project = (string) $input->getArgument('project');

            [$changed, $warning] = self::commit($this->sync, fn (): bool => $this->access->revoke($username, $project));
            $out->emit($out->withWarning(['username' => $username, 'project' => $project, 'revoked' => $changed], $warning), static function (JsonOutput $out) use ($username, $project, $changed): void {
                $out->success($changed
                    ? \sprintf('"%s" is no longer a member of "%s".', $username, $project)
                    : \sprintf('"%s" was not a member of "%s": nothing changed.', $username, $project));
            });
        });
    }
}
