<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:list', description: 'List the instances')]
final class ListCommand extends Command
{
    public function __construct(private readonly InstanceRepository $instances, private readonly ProjectRepository $projects)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Only the instances of this project')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Only the instances in this state')
        ;
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());

        return $out->run(function () use ($input, $out): void {
            $project = $input->getOption('project');
            $state = $input->getOption('state');
            if (\is_string($state) && null === InstanceState::tryFrom($state)) {
                throw new ValidationFailed(['state' => 'Unknown state.']);
            }
            if (\is_string($project)) {
                $found = $this->instances->byProject($this->projects->findByName($project) ?? throw NotFound::project($project));
            } else {
                $found = $this->instances->all();
            }
            $rows = [];
            foreach ($found as $instance) {
                if (\is_string($state) && $instance->state()->value !== $state) {
                    continue;
                }
                $rows[] = self::row($instance);
            }

            $out->emit(['instances' => $rows], static function (JsonOutput $out) use ($rows): void {
                if ([] === $rows) {
                    $out->success('No instances.');

                    return;
                }
                $out->table(
                    ['Id', 'State', 'Branch', 'Template', 'Head', 'RAM MiB', 'Deployed'],
                    array_map(static fn (array $r): array => [$r['id'], $r['state'], $r['branch'], $r['template'], substr((string) $r['head'], 0, 8), $r['ramMib'] ?? '-', $r['deployedAt'] ?? '-'], $rows),
                );
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Instance $i): array
    {
        return [
            'id' => $i->id(),
            'project' => $i->project()->name(),
            'state' => $i->state()->value,
            'branch' => $i->branch(),
            'template' => $i->templateName(),
            'head' => $i->headSha(),
            'ramMib' => $i->ramMib(),
            'deployedAt' => $i->deployedAt()?->format(\DATE_ATOM),
        ];
    }
}
