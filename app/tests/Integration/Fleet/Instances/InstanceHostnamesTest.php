<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Project\Project;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceHostnamesTest extends KernelTestCase
{
    use FleetDatabase;

    private HostSettingsRepository $host;

    protected function setUp(): void
    {
        $this->host = new HostSettingsRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function project(string ...$hostnames): Project
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeHostnames(array_values($hostnames));

        return $project;
    }

    public function testPrimaryAliasesAndSiteUrl(): void
    {
        $this->host->getOrCreate('fleet.example.com');
        $hostnames = new InstanceHostnames($this->host);

        self::assertSame('acme--dev.fleet.example.com', $hostnames->primary('acme--dev'));
        self::assertSame('https://acme--dev.fleet.example.com', $hostnames->siteUrl('acme--dev'));
        self::assertSame(
            ['edge-acme--dev.fleet.example.com', 'shop-acme--dev.fleet.example.com'],
            $hostnames->aliases($this->project('edge', 'shop'), 'acme--dev'),
        );
        self::assertSame([], $hostnames->aliases($this->project(), 'acme--dev'));
    }

    public function testALabelOver63IsRefused(): void
    {
        $this->host->getOrCreate('fleet.example.com');

        $this->expectException(ValidationFailed::class);
        new InstanceHostnames($this->host)->aliases($this->project('edge'), 'acme--'.str_repeat('a', 56));
    }

    public function testBeforeTheHostIsSetUpThereIsNoDomain(): void
    {
        $this->expectException(Refused::class);

        new InstanceHostnames($this->host)->primary('acme--dev');
    }
}
