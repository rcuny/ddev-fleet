<?php

declare(strict_types=1);

namespace App\Fleet\Host\Docker;

use App\Fleet\Audit\AuditLog;
use Psr\Log\LoggerInterface;

/**
 * FLE-298: what the status watcher does with the socket check. A wrong socket is logged as a warning, written to the audit log
 * (once per distinct state, not every tick) and restored when the process may; the last bad state is kept for the host page.
 */
final class DockerSocketWatch
{
    private ?string $reported = null;
    private ?DockerSocketState $last = null;

    public function __construct(
        private readonly DockerSocketCheck $check,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function tick(): DockerSocketState
    {
        $state = $this->check->check();
        if ($state->ok) {
            $this->reported = null;
            $this->last = $state;

            return $state;
        }
        $restored = $this->check->restore();
        $after = $this->check->check();
        $note = $state->summary.($restored ? ' - restored to root:docker 0660' : ' - NOT restored (root needed: sudo fleet host:docker-socket --restore)');
        if ($this->reported !== $note) {
            $this->reported = $note;
            $this->logger->warning('The Docker socket is not root:docker 0660.', ['socket' => $state->path, 'detail' => $note]);
            $this->audit->record('system', AuditLog::HOST_DOCKER_SOCKET, $note);
        }
        $this->last = $after;

        return $after;
    }

    public function last(): ?DockerSocketState
    {
        return $this->last;
    }
}
