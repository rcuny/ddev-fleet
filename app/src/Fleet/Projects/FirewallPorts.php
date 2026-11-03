<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * The public ports the host firewall has to let through: 80 and 443 (Caddy, always), plus the public port of every
 * catalogue entry that at least one project uses. The installer never changes the firewall: this is guidance.
 */
final readonly class FirewallPorts
{
    public function __construct(private NamedPortService $ports)
    {
    }

    /**
     * @return list<array{port: int, purpose: string, projects: list<string>, ufw: string}> ordered by port
     */
    public function list(): array
    {
        $rows = [
            80 => ['port' => 80, 'purpose' => 'http (certificate challenge and redirect to https)', 'projects' => [], 'ufw' => 'ufw allow 80/tcp'],
            443 => ['port' => 443, 'purpose' => 'https', 'projects' => [], 'ufw' => 'ufw allow 443/tcp'],
        ];
        foreach ($this->ports->list() as $port) {
            $projects = $this->ports->usedBy($port);
            if ([] === $projects) {
                continue;
            }
            $public = $port->publicPort();
            $rows[$public] = ['port' => $public, 'purpose' => $port->name(), 'projects' => $projects, 'ufw' => \sprintf('ufw allow %d/tcp', $public)];
        }
        ksort($rows);

        return array_values($rows);
    }
}
