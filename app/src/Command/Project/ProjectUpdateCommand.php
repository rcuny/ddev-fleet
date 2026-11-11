<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\PortMirror\PortMirrorSwitch;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ProjectSnapshot;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:update', description: 'Change a project (only the options you give)')]
final class ProjectUpdateCommand extends Command
{
    public function __construct(private readonly ProjectService $projects, private readonly InstanceSiteRefresher $sites, private readonly PortMirrorSwitch $mirror, private readonly ProjectRepository $repository, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name');
        ProjectOptions::configure($this, true);
        $this
            ->addOption('allow-privileged-overrides', null, InputOption::VALUE_NONE, 'Let the project\'s compose overrides do what the deploy guard refuses (Docker socket, privileged, host mounts): the containers can then take over the host')
            ->addOption('no-allow-privileged-overrides', null, InputOption::VALUE_NONE, 'Turn privileged compose overrides off again (the default)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $current = ProjectInput::fromProject($this->projects->get($name));
            $allow = $input->getOption('allow-privileged-overrides');
            $deny = $input->getOption('no-allow-privileged-overrides');
            if (true === $allow && true === $deny) {
                throw new Refused('Give either --allow-privileged-overrides or --no-allow-privileged-overrides, not both.');
            }
            $project = $this->projects->update($name, $current->with(ProjectOptions::changes($input, true, $current->gitBotMode)));
            $note = true === $allow || true === $deny ? $this->setPrivilegedOverrides($project, true === $allow) : null;
            $out->emit($out->withWarning(ProjectSnapshot::fromProject($project)->toArray(), self::joined(self::joined($this->sites->takeWarning(), $this->mirror->sync()), $note)), static function (JsonOutput $out) use ($project): void {
                $out->success(\sprintf('Project "%s" updated.', $project->name()));
            });
        });
    }

    private function setPrivilegedOverrides(\App\Domain\Project\Project $project, bool $enable): ?string
    {
        if ($enable === $project->allowPrivilegedOverrides()) {
            return null;
        }
        $project->changeAllowPrivilegedOverrides($enable);
        $this->repository->save($project);
        $this->audit->record(self::actor(), AuditLog::PROJECT_PRIVILEGED_OVERRIDES, $project->name().'/'.($enable ? 'on' : 'off'));

        return $enable ? 'privileged overrides are ON for "'.$project->name().'": its containers can mount the Docker socket, run privileged and share host namespaces, so they can take over the host and every other instance on it. Use it only for code you fully trust.' : null;
    }

    /** The person behind the root CLI: the sudo caller when there is one, else the OS user. */
    private static function actor(): string
    {
        $sudo = getenv('SUDO_USER');
        if (\is_string($sudo) && '' !== $sudo) {
            return 'cli:'.$sudo;
        }
        $info = \function_exists('posix_getpwuid') && \function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;

        return false === $info ? AuditLog::CLI_ACTOR : 'cli:'.$info['name'];
    }

    private static function joined(?string $first, ?string $second): ?string
    {
        $parts = array_values(array_filter([$first, $second], static fn (?string $warning): bool => null !== $warning));

        return [] === $parts ? null : implode(' ', $parts);
    }
}
