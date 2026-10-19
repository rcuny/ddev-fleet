<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:webhook:log', description: 'Show the latest webhook deliveries (newest first)')]
final class WebhookLogCommand extends Command
{
    public function __construct(private readonly WebhookRepository $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Only this project');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many deliveries', '20');
        $this->addOption('hide-ignored', null, InputOption::VALUE_NONE, 'Leave out ignored deliveries');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($input, $out): void {
            $project = $input->getOption('project');
            $limit = max(1, min(500, (int) $input->getOption('limit')));
            $rows = array_map(static fn (WebhookDelivery $d): array => [
                'received_at' => $d->receivedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'project' => $d->projectName(),
                'source' => $d->source()->value,
                'event' => $d->event(),
                'delivery_id' => $d->deliveryId(),
                'subject' => $d->subject(),
                'change' => $d->change(),
                'result' => $d->result(),
                'reason' => $d->reason(),
                'rule' => $d->rule(),
                'decision' => $d->decision(),
            ], $this->webhooks->deliveries(\is_string($project) ? $project : null, true === $input->getOption('hide-ignored'), $limit));

            $out->emit($rows, static function (JsonOutput $out) use ($rows): void {
                if ([] === $rows) {
                    $out->success('No webhook deliveries logged.');

                    return;
                }
                $out->table(['WHEN (UTC)', 'PROJECT', 'SOURCE', 'SUBJECT', 'RESULT', 'DECISION'], array_map(
                    static fn (array $r): array => [$r['received_at'], $r['project'], $r['source'], $r['subject'] ?? $r['event'], $r['result'], $r['decision'] ?? $r['reason'] ?? '-'],
                    $rows,
                ));
            });
        });
    }
}
