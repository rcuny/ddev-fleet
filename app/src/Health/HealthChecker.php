<?php

declare(strict_types=1);

namespace App\Health;

use App\Fleet\FleetPaths;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class HealthChecker
{
    public function __construct(
        private readonly FleetPaths $paths,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $fleetDb,
        #[Autowire(service: 'doctrine.dbal.queue_connection')]
        private readonly Connection $queueDb,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function check(): HealthReport
    {
        return new HealthReport([
            'fleet_db' => $this->attempt('fleet_db', fn () => 1 === (int) $this->fleetDb->fetchOne('SELECT 1')),
            'queue_db' => $this->attempt('queue_db', fn () => 1 === (int) $this->queueDb->fetchOne('SELECT 1')),
            'home_writable' => $this->attempt('home_writable', $this->homeIsWritable(...)),
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
    private function attempt(string $name, callable $check): bool
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            // The HTTP response stays detail-free; the cause goes to the log only.
            $this->logger->warning('Health check "{check}" failed: {message}', [
                'check' => $name,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
