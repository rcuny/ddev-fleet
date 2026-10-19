<?php

declare(strict_types=1);

namespace App\Command\Support;

use App\Fleet\Instances\Selector;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the instance commands share: the selection options, the confirmation of bulk work, and number parsing.
 */
final class InstanceOptions
{
    public static function addSelection(Command $command): void
    {
        $command
            ->addArgument('id', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Instance id(s), as <project>--<label>')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every instance')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Every instance of this project')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Every instance in this state')
        ;
    }

    public static function selector(InputInterface $input): Selector
    {
        $ids = $input->getArgument('id');
        $project = $input->getOption('project');
        $state = $input->getOption('state');

        return new Selector(
            \is_array($ids) ? array_values(array_map(strval(...), $ids)) : [],
            true === $input->getOption('all'),
            \is_string($project) ? $project : null,
            \is_string($state) ? $state : null,
        );
    }

    /**
     * One explicit id goes ahead unasked; a selector or several ids need --yes (refused when nobody can answer) or the
     * typed number of instances at the prompt.
     *
     * @throws Refused
     */
    public static function confirm(InputInterface $input, OutputInterface $output, JsonOutput $out, Selector $selector, int $count): void
    {
        if (1 === \count($selector->ids) && !$selector->all && null === $selector->project && null === $selector->state) {
            return;
        }
        if (true === $input->getOption('yes')) {
            return;
        }
        if ($out->isJson() || !$input->isInteractive()) {
            throw new Refused(\sprintf('Not done: this affects %d instances, pass --yes to confirm.', $count));
        }
        $answer = new SymfonyStyle($input, $output)->ask(\sprintf('This affects %d instances. Type %1$d to confirm', $count));
        if (trim((string) $answer) !== (string) $count) {
            throw new Refused('Aborted: the number did not match.');
        }
    }

    /**
     * @throws ValidationFailed
     */
    public static function seconds(InputInterface $input): ?float
    {
        $timeout = $input->getOption('timeout');
        if (null === $timeout) {
            return null;
        }
        if (!\is_string($timeout) || !is_numeric($timeout) || (float) $timeout <= 0) {
            throw new ValidationFailed(['timeout' => 'The timeout must be a number of seconds above 0.']);
        }

        return (float) $timeout;
    }
}
