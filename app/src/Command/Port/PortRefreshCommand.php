<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Projects\ProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:refresh', description: 'Rewrite the Caddy sites of the instances so they carry their projects\' named ports')]
final class PortRefreshCommand extends Command
{
    public function __construct(private readonly ProjectService $projects, private readonly InstanceSiteRefresher $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Only the instances of this project');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());
        $failed = false;

        $code = $out->run(function () use ($input, $out, &$failed): void {
            $name = $input->getOption('project');
            $result = $this->sites->refresh(\is_string($name) && '' !== $name ? $this->projects->get($name) : null);
            $failed = [] !== $result->failed;
            $out->emit(['rewritten' => $result->rewritten, 'failed' => (object) $result->failed], static function (JsonOutput $out) use ($result): void {
                $out->success(\sprintf('%d Caddy site(s) rewritten.', $result->rewritten));
                foreach ($result->failed as $id => $reason) {
                    $out->fail(\sprintf('%s: %s', $id, $reason), JsonOutput::REFUSED);
                }
            });
        });

        return JsonOutput::OK === $code && $failed ? JsonOutput::REFUSED : $code;
    }
}
