<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Fleet\Webhooks\WebhookUrls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:webhook:list', description: 'List the webhooks that have a secret, with their addresses and rules (never the secrets)')]
final class WebhookListCommand extends Command
{
    public function __construct(private readonly WebhookRepository $webhooks, private readonly WebhookUrls $urls)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($out): void {
            $rows = array_map(fn (WebhookEndpoint $e): array => [
                'project' => $e->project()->name(),
                'source' => $e->source()->value,
                'url' => $this->urls->url($e->source(), $e->project()->name()),
                'token' => $e->hasToken(),
                'rotated_at' => $e->rotatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'rules' => array_map(static fn ($r): array => ['id' => $r->id(), 'action' => $r->action(), 'match' => $r->match(), 'params' => $r->params()], $e->rules()),
            ], $this->webhooks->endpoints());
            $out->emit($rows, static function (JsonOutput $out) use ($rows): void {
                if ([] === $rows) {
                    $out->success('No webhook has a secret yet.');

                    return;
                }
                $out->table(['PROJECT', 'SOURCE', 'URL', 'RULES', 'TOKEN'], array_map(
                    static fn (array $r): array => [$r['project'], $r['source'], $r['url'], \count($r['rules']), $r['token'] ? 'set' : '-'],
                    $rows,
                ));
            });
        });
    }
}
