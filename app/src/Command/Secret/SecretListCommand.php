<?php

declare(strict_types=1);

namespace App\Command\Secret;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:secret:list', description: 'List the names of the fleet-wide or a project\'s secrets (never a value)')]
final class SecretListCommand extends Command
{
    public function __construct(private readonly SecretService $secrets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'List this project\'s secrets instead of the fleet-wide ones');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::secret());

        return $out->run(function () use ($input, $out): void {
            $project = SecretOptions::project($input);
            $overview = $this->secrets->overview($project);

            $items = array_map(static fn (SecretSummary $secret): array => [
                'name' => $secret->name,
                'placeholder' => $secret->placeholder(),
                'updated_by' => $secret->updatedBy,
                'updated_at' => $secret->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                'referenced' => $overview->referenced($secret),
            ], $overview->secrets);

            $out->emit(
                ['scope' => null === $project ? 'fleet' : 'project', 'project' => $project, 'secrets' => $items],
                static function (JsonOutput $out) use ($items, $overview, $project): void {
                    if ([] === $items) {
                        $out->success(null === $project ? 'No fleet-wide secrets.' : \sprintf('Project "%s" has no secrets.', $project));

                        return;
                    }
                    $out->table(['Name', 'Placeholder', 'Updated by', 'Updated at', 'Used'], array_map(static fn (array $item): array => [
                        $item['name'],
                        $item['placeholder'],
                        $item['updated_by'],
                        $item['updated_at'],
                        null === $item['referenced'] ? '-' : ($item['referenced'] ? 'yes' : 'NO'),
                    ], $items));
                    foreach ($overview->unreferenced() as $secret) {
                        $out->success(\sprintf('%s: no asset file mentions %s, so instances never receive it.', $secret->name, $secret->placeholder()));
                    }
                },
            );
        });
    }
}
