<?php

declare(strict_types=1);

namespace App\Command\Asset;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Assets\AssetEntry;
use App\Fleet\Assets\AssetStore;
use App\Fleet\Assets\ByteSize;
use App\Fleet\Projects\ProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:asset:list', description: 'List the files in a project\'s assets directory')]
final class AssetListCommand extends Command
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly AssetStore $assets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Project name')
            ->addArgument('path', InputArgument::OPTIONAL, 'Folder inside the assets directory (default: the top)', '')
        ;
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::asset());

        return $out->run(function () use ($input, $out): void {
            $project = $this->projects->get((string) $input->getArgument('project'))->name();
            $listing = $this->assets->list($project, (string) $input->getArgument('path'));

            $out->emit(
                [
                    'project' => $project,
                    'path' => $listing->path(),
                    'entries' => array_map(static fn (AssetEntry $entry): array => $entry->toArray(), $listing->entries),
                    'truncated' => $listing->truncated,
                ],
                static function (JsonOutput $out) use ($listing): void {
                    $out->table(
                        ['TYPE', 'NAME', 'SIZE', 'MODIFIED (UTC)'],
                        array_map(static fn (AssetEntry $entry): array => [
                            $entry->kind,
                            $entry->name.match (true) {
                                AssetEntry::LINK === $entry->kind => \sprintf(' -> %s (%s)', $entry->linkTarget, $entry->linkSeeded ? 'symbolic link, seeded' : 'symbolic link, not seeded: '.$entry->linkNote),
                                !$entry->usable => ' (not addressable)',
                                default => '',
                            },
                            null === $entry->size ? '-' : ByteSize::format($entry->size),
                            gmdate('Y-m-d H:i', $entry->modified),
                        ], $listing->entries),
                    );
                    if ($listing->truncated) {
                        $out->success(\sprintf('Only the first %d items are shown.', AssetStore::MAX_ENTRIES));
                    }
                },
            );
        });
    }
}
