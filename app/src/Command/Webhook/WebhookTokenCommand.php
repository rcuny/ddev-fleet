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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Bitbucket access token (scope `pipeline:write`) a run-pipeline rule calls the API with. Like a secret value it is
 * never an argument or an option: a person types it with the input hidden, a script pipes it on standard input. It is
 * shown nowhere afterwards.
 */
#[AsCommand(name: 'fleet:webhook:token', description: 'Set, replace or (with --clear) remove the Bitbucket pipeline token of a project (read from a hidden prompt or standard input)')]
final class WebhookTokenCommand extends Command
{
    private const int MAX_READ = 2048 + 16;

    public function __construct(private readonly WebhookService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the stored token instead of setting one');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($input, $output, $out): void {
            $project = (string) $input->getArgument('project');
            if (true === $input->getOption('clear')) {
                $removed = $this->webhooks->clearPipelineToken($project);
                $out->emit(['project' => $project, 'cleared' => $removed], static function (JsonOutput $out) use ($project, $removed): void {
                    $out->success($removed ? \sprintf('The pipeline token of %s was removed.', $project) : \sprintf('%s had no pipeline token.', $project));
                });

                return;
            }

            $this->webhooks->setPipelineToken($project, $this->value($input, $output));
            $out->emit(['project' => $project, 'saved' => true], static function (JsonOutput $out) use ($project): void {
                $out->success(\sprintf('The pipeline token of %s is saved. It cannot be shown again.', $project));
            });
        });
    }

    private function value(InputInterface $input, OutputInterface $output): string
    {
        if ($input->isInteractive()) {
            $answer = new SymfonyStyle($input, $output)->askHidden('Token (input is hidden)');

            return \is_string($answer) ? $answer : '';
        }
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $content = stream_get_contents($stream ?? \STDIN, self::MAX_READ);

        return false === $content ? '' : $content;
    }
}
