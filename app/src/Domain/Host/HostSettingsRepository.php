<?php

declare(strict_types=1);

namespace App\Domain\Host;

use Doctrine\ORM\EntityManagerInterface;

final readonly class HostSettingsRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(): ?HostSettings
    {
        return $this->em->find(HostSettings::class, HostSettings::ID);
    }

    /**
     * The row as stored right now. get() hands back the entity this process already holds (the identity map);
     * another process may have changed the row since. This re-reads it, overwriting unflushed changes.
     */
    public function fresh(): ?HostSettings
    {
        $settings = $this->get();
        if (null !== $settings) {
            $this->em->refresh($settings);
        }

        return $settings;
    }

    /**
     * Returns the singleton row, creating it for $domain when absent. An existing row is returned untouched
     * (changing its domain is an explicit HostSettings::changeDomain()).
     */
    public function getOrCreate(string $domain): HostSettings
    {
        $settings = $this->get();
        if (null === $settings) {
            $settings = new HostSettings($domain);
            $this->save($settings);
        }

        return $settings;
    }

    public function save(HostSettings $settings): void
    {
        $this->em->persist($settings);
        $this->em->flush();
    }
}
