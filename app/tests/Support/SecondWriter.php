<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Simulates a second writer (another request) that wins a race: it writes through its own connection and entity
 * manager at the moment the first entity manager persists, which is after the service's existence checks and before
 * the flush.
 */
trait SecondWriter
{
    /**
     * A second entity manager on its own connection to the same database file.
     */
    private function secondEntityManager(EntityManagerInterface $first): EntityManager
    {
        $connection = $first->getConnection();

        return new EntityManager(
            DriverManager::getConnection($connection->getParams(), $connection->getConfiguration()),
            $first->getConfiguration(),
        );
    }

    /**
     * Runs $write once, when $first persists its next entity.
     */
    private function beforeNextPersist(EntityManagerInterface $first, \Closure $write): void
    {
        $first->getEventManager()->addEventListener(Events::prePersist, new class($write) {
            private bool $done = false;

            public function __construct(private readonly \Closure $write)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if (!$this->done) {
                    $this->done = true;
                    ($this->write)();
                }
            }
        });
    }
}
