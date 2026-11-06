<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * One difference between a project's stored network settings and a submission. A change that exposes something or
 * changes who may reach it is risky: the page asks for a confirmation before it is saved.
 */
final readonly class NetworkChange
{
    public const string HOSTNAME_ADD = 'hostname-add';
    public const string HOSTNAME_REMOVE = 'hostname-remove';
    public const string PORT_ASSIGN = 'port-assign';
    public const string PORT_UNASSIGN = 'port-unassign';
    public const string PORT_ACCESS = 'port-access';

    public function __construct(
        public string $kind,
        public string $subject,
        public bool $risky,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }

    /**
     * The sentence shown in the confirmation. It names things, never a secret.
     */
    public function describe(): string
    {
        return match ($this->kind) {
            self::HOSTNAME_ADD => \sprintf('Add the hostname "%s": every instance also answers on this new name.', $this->subject),
            self::HOSTNAME_REMOVE => \sprintf('Remove the hostname "%s".', $this->subject),
            self::PORT_ASSIGN => \sprintf('Assign the public port "%s": it is reachable without the login.', $this->subject),
            self::PORT_UNASSIGN => \sprintf('Unassign the public port "%s".', $this->subject),
            default => \sprintf('Change the access of the port "%s" from %s to %s. This applies to every project that uses the port.', $this->subject, $this->from, $this->to),
        };
    }

    /**
     * The audit subject: names and the old and new value, no free text.
     */
    public function auditSubject(string $project): string
    {
        return match ($this->kind) {
            self::PORT_ACCESS => \sprintf('%s port %s %s -> %s', $project, $this->subject, $this->from, $this->to),
            self::HOSTNAME_ADD, self::PORT_ASSIGN => \sprintf('%s %s added', $project, $this->subject),
            default => \sprintf('%s %s removed', $project, $this->subject),
        };
    }

    public function auditAction(): string
    {
        return match ($this->kind) {
            self::PORT_ACCESS => 'port.access',
            self::HOSTNAME_ADD, self::HOSTNAME_REMOVE => 'project.hostname',
            default => 'project.port',
        };
    }
}
