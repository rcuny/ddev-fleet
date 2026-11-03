<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\PortMirror\PortMirror;
use App\Fleet\Projects\ProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:refresh', description: 'Rewrite the Caddy sites of the instances and the router port mirror files, and retry a failed mirror')]
final class PortRefreshCommand extends Command
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly InstanceSiteRefresher $sites,
        private readonly PortMirror $mirror,
    ) {
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

            // An explicit retry: forget a failed mirror apply, then write the files again. The router is not restarted
            // here; the next deploy's converge does that.
            $mirror = ['state' => 'pending', 'mirrored' => [], 'skipped' => []];
            $mirrorError = null;
            try {
                $this->mirror->clearFailedMarker();
                $sync = $this->mirror->sync();
                $mirror['mirrored'] = $sync->mirrored;
                $mirror['skipped'] = $sync->skipped;
                $mirror['state'] = $this->mirror->isLive() ? 'live' : 'pending';
            } catch (\RuntimeException $e) {
                $mirrorError = 'the mirror files were not updated: '.$e->getMessage();
                $mirror['error'] = $mirrorError;
                $failed = true;
            }

            $out->emit(['rewritten' => $result->rewritten, 'failed' => (object) $result->failed, 'mirror' => $mirror], static function (JsonOutput $out) use ($result, $mirror, $mirrorError): void {
                $out->success(\sprintf('%d Caddy site(s) rewritten.', $result->rewritten));
                foreach ($result->failed as $id => $reason) {
                    $out->fail(\sprintf('%s: %s', $id, $reason), JsonOutput::REFUSED);
                }
                $out->success('router mirror: '.$mirror['state']);
                foreach ($mirror['skipped'] as $reason) {
                    $out->success('  '.$reason);
                }
                if (null !== $mirrorError) {
                    $out->fail($mirrorError, JsonOutput::REFUSED);
                }
            });
        });

        return JsonOutput::OK === $code && $failed ? JsonOutput::REFUSED : $code;
    }
}
