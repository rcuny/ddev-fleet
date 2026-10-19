<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Webhooks\WebhookService;
use App\Fleet\Webhooks\WebhookUrls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:webhook:secret', description: 'Create (or with --rotate, replace) the secret of a project webhook; the secret is shown once')]
final class WebhookSecretCommand extends Command
{
    public function __construct(private readonly WebhookService $webhooks, private readonly WebhookUrls $urls)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        WebhookSourceOption::add($this);
        $this->addOption('rotate', null, InputOption::VALUE_NONE, 'Replace an existing secret (the old one stops working at once)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($input, $out): void {
            $project = (string) $input->getArgument('project');
            $source = WebhookSourceOption::read($input);
            $secret = $this->webhooks->issueSecret($project, $source, true === $input->getOption('rotate'));
            $url = $this->urls->url($source, $project);

            // Labelled lines (and --json): a script reads the field by name, never by line number.
            $out->emit(['project' => $project, 'source' => $source->value, 'url' => $url, 'secret' => $secret], static function (JsonOutput $out) use ($url, $secret): void {
                $out->pairs(['url' => $url, 'secret' => $secret]);
                $out->success('Shown once: paste the secret into the sender\'s Secret field now. It cannot be shown again.');
            });
        });
    }
}
