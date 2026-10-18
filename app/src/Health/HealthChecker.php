<?php

declare(strict_types=1);

namespace App\Health;

use App\Fleet\FleetPaths;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class HealthChecker
{
    public function __construct(
        private readonly FleetPaths $paths,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $fleetDb,
        #[Autowire(service: 'doctrine.dbal.queue_connection')]
        private readonly Connection $queueDb,
    ) {
    }

    public function check(): HealthReport
    {
        return new HealthReport([
            'fleet_db' => $this->attempt(fn () => 1 === (int) $this->fleetDb->fetchOne('SELECT 1')),
            'queue_db' => $this->attempt(fn () => 1 === (int) $this->queueDb->fetchOne('SELECT 1')),
            'home_writable' => $this->attempt($this->homeIsWritable(...)),
        ]);
    }

    private function homeIsWritable(): bool
    {
        $this->paths->ensureLayout();
        $probe = $this->paths->varDir().'/.health-'.bin2hex(random_bytes(6));
        try {
            return false !== file_put_contents($probe, 'ok');
        } finally {
            @unlink($probe);
        }
    }

    /**
     * @param callable(): bool $check
     */
    private function attempt(callable $check): bool
    {
        try {
            return $check();
        } catch (\Throwable) {
            return false;
        }
    }
}
