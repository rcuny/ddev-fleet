<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Webhooks\WebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:webhook:rule:remove', description: 'Remove a rule from a project webhook (see fleet:webhook:list for the ids)')]
final class WebhookRuleRemoveCommand extends Command
{
    public function __construct(private readonly WebhookService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        $this->addArgument('rule', InputArgument::REQUIRED, 'Rule id');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($input, $out): void {
            $id = (int) $input->getArgument('rule');
            $this->webhooks->removeRule((string) $input->getArgument('project'), $id);
            $out->emit(['removed' => $id], static function (JsonOutput $out) use ($id): void {
                $out->success(\sprintf('Rule %d removed.', $id));
            });
        });
    }
}
