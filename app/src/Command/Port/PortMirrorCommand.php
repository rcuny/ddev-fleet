<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\PortMirror\PortMirror;
use App\Fleet\PortMirror\PortMirrorSwitch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:mirror', description: 'Show, or switch on or off, the in-network mirror of the named ports')]
final class PortMirrorCommand extends Command
{
    public function __construct(private readonly PortMirror $mirror, private readonly PortMirrorSwitch $switch)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('state', InputArgument::OPTIONAL, 'on or off; without it the current state is shown');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::port());
        $state = $input->getArgument('state');
        if (null !== $state && !\in_array($state, ['on', 'off'], true)) {
            return $out->fail('Use "on" or "off".', JsonOutput::INVALID, ['<state>' => 'Use "on" or "off".']);
        }

        return $out->run(function () use ($out, $state): void {
            $warning = null === $state ? null : $this->switch->set('on' === $state);
            $plan = $this->mirror->plan();
            $enabled = $this->mirror->enabled();
            $payload = $out->withWarning([
                'enabled' => $enabled,
                'mirrored' => $plan->publicPorts(),
                'skipped' => $plan->skipped,
            ], $warning);
            $out->emit($payload, static function (JsonOutput $out) use ($enabled, $plan): void {
                $out->pairs([
                    'port mirror' => $enabled ? 'on' : 'off',
                    'public ports' => array_map('strval', $plan->publicPorts()),
                    'skipped' => $plan->skipped,
                ]);
            });
        });
    }
}
