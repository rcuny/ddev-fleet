<?php

declare(strict_types=1);

namespace App\Command\Asset;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Assets\ByteSize;
use App\Fleet\Assets\UploadService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:asset:gc', description: 'Remove abandoned browser uploads and leftover temporary files')]
final class AssetGcCommand extends Command
{
    public function __construct(private readonly UploadService $uploads)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::asset());

        return $out->run(function () use ($out): void {
            $result = $this->uploads->gc();
            $out->emit($result->toArray(), static function (JsonOutput $out) use ($result): void {
                $out->success(\sprintf(
                    'Removed %d abandoned upload(s) and %d temporary file(s), freeing %s.',
                    $result->uploads,
                    $result->temporaryFiles,
                    ByteSize::format($result->bytes),
                ));
            });
        });
    }
}
