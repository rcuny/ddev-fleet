<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Fleet\Export\ImportItem;
use App\Fleet\Export\ImportPlan;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints an import plan: the document with --json, else a table on stdout with the errors and warnings on stderr.
 */
final class ImportOutput
{
    public static function show(JsonOutput $out, OutputInterface $output, ImportPlan $plan): void
    {
        $out->emit($plan->toArray(), static function (JsonOutput $out) use ($output, $plan): void {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            if ([] !== $plan->items) {
                $out->table(['Kind', 'Name', 'Action', 'Note'], array_map(
                    static fn (ImportItem $item): array => [$item->kind, $item->name, $item->action, $item->note ?? ''],
                    $plan->items,
                ));
                $counts = $plan->counts();
                $out->success(implode(', ', array_map(static fn (string $action): string => $counts[$action].' '.$action, ImportItem::ACTIONS)).'.');
            }
            foreach ($plan->errors as $error) {
                $stderr->writeln('Error: '.$error, OutputInterface::OUTPUT_RAW);
            }
            foreach ($plan->warnings as $warning) {
                $stderr->writeln('Warning: '.$warning, OutputInterface::OUTPUT_RAW);
            }
        });
    }
}
