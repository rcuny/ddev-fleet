<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Fleet\PortMirror\MirrorFiles;
use App\Fleet\PortMirror\MirrorLock;
use App\Fleet\PortMirror\MirrorPlanner;
use App\Fleet\PortMirror\PortMirror;
use App\Fleet\PortMirror\RouterConverger;
use App\Fleet\PortMirror\TraefikDirectory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The port mirror wired by hand over a migrated database, a throw-away "fleet user home" with `.ddev/traefik`, a
 * scripted router API and the recording runner.
 */
abstract class PortMirrorTestCase extends InstanceJobTestCase
{
    protected string $userHome;
    protected TraefikDirectory $traefik;
    protected MirrorPlanner $planner;
    protected MirrorFiles $files;
    protected FakeLiveEntrypoints $live;
    protected NamedPortRepository $ports;
    protected HostSettingsRepository $hostSettings;
    protected PortMirror $mirror;
    protected RouterConverger $converger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userHome = sys_get_temp_dir().'/fleet-user-home-'.bin2hex(random_bytes(6));
        mkdir($this->userHome.'/.ddev/traefik', 0o755, true);
        $this->traefik = new TraefikDirectory($this->userHome);
        $this->planner = new MirrorPlanner($this->traefik);
        $this->files = new MirrorFiles($this->traefik);
        $this->live = new FakeLiveEntrypoints(null);
        $this->ports = new NamedPortRepository($this->em);
        $this->hostSettings = new HostSettingsRepository($this->em);
        $lock = new MirrorLock(new LockFactory(new FlockStore($this->paths->locksDir())), 0.0);
        $this->mirror = new PortMirror($this->hostSettings, $this->ports, $this->planner, $this->files, $lock, $this->live, $this->traefik);
        $this->converger = new RouterConverger($this->mirror, $this->planner, $this->files, $this->live, $this->runner, $this->ddev, $lock, $this->traefik, 0.0, static function (float $seconds): void {
        });
    }

    protected function tearDown(): void
    {
        self::removeTree($this->userHome);
        parent::tearDown();
    }

    protected function catalogue(NamedPort ...$ports): void
    {
        foreach ($ports as $port) {
            $this->ports->save($port);
        }
    }

    protected function globalConfig(string $yaml): void
    {
        file_put_contents($this->traefik->globalConfigFile(), $yaml);
    }

    protected function foreign(string $name, string $yaml): void
    {
        file_put_contents($this->traefik->dir().'/'.$name, $yaml);
    }

    protected function switchMirror(bool $enabled): void
    {
        $host = $this->hostSettings->get();
        self::assertNotNull($host);
        $host->changePortMirror($enabled);
        $this->hostSettings->save($host);
    }

    protected function read(string $file): string
    {
        return (string) file_get_contents($file);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
