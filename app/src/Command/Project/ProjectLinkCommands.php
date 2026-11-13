<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Domain\Project\ProjectLink;
use App\Fleet\Audit\AuditLog;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared bits of the three `fleet project:link:*` commands (FLE-299). Not a command.
 */
final class ProjectLinkCommands
{
    public static function addTarget(\Symfony\Component\Console\Command\Command $command): void
    {
        $command->addArgument('source', InputArgument::REQUIRED, 'The project whose web containers may open the connection');
        $command->addArgument('target', InputArgument::REQUIRED, 'The project that is reached');
        $command->addOption('service', null, InputOption::VALUE_REQUIRED, 'The compose service of the target (default web)', 'web');
        $command->addOption('port', null, InputOption::VALUE_REQUIRED, 'The TCP port of that service');
        $command->addOption('label', null, InputOption::VALUE_REQUIRED, '"same" (the target instance with the source instance\'s label) or a fixed instance label', ProjectLink::SAME_LABEL);
    }

    public static function fields(): FieldErrors
    {
        return new FieldErrors(['source', 'target']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ProjectLink $link): array
    {
        return [
            'source' => $link->source()->name(),
            'target' => $link->target()->name(),
            'service' => $link->service(),
            'port' => $link->port(),
            'label' => $link->targetLabel(),
            'note' => $link->note(),
            'createdBy' => $link->createdBy(),
            'createdAt' => $link->createdAt()->format(\DATE_ATOM),
        ];
    }

    public static function port(InputInterface $input): int
    {
        $port = $input->getOption('port');

        return \is_string($port) && ctype_digit($port) ? (int) $port : 0;
    }

    public static function actor(): string
    {
        return AuditLog::CLI_ACTOR;
    }
}
