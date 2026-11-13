<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Tests\Support\PortMirrorTestCase;

final class PortMirrorSyncTest extends PortMirrorTestCase
{
    private const string GOLDEN_STATIC = <<<'YAML'
        # Managed by ddev-fleet: do not edit.
        entryPoints:
          fleet-mirror-443:
            address: ":443"
          fleet-mirror-9108:
            address: ":9108"
          fleet-mirror-9200:
            address: ":9200"

        YAML;

    private const string GOLDEN_DYNAMIC = <<<'YAML'
        # Managed by ddev-fleet: do not edit.
        tcp:
          routers:
            fleet-mirror-443:
              entryPoints: [fleet-mirror-443]
              rule: "HostSNI(`*`)"
              service: fleet-mirror-443
              tls:
                passthrough: true
            fleet-mirror-9108:
              entryPoints: [fleet-mirror-9108]
              rule: "HostSNI(`*`)"
              service: fleet-mirror-9108
              tls: {}
            fleet-mirror-9200:
              entryPoints: [fleet-mirror-9200]
              rule: "HostSNI(`*`)"
              service: fleet-mirror-9200
              tls: {}
          services:
            fleet-mirror-443:
              loadBalancer:
                servers:
                  - address: "127.0.0.1:8443"
            fleet-mirror-9108:
              loadBalancer:
                servers:
                  - address: "127.0.0.1:8108"
            fleet-mirror-9200:
              loadBalancer:
                servers:
                  - address: "127.0.0.1:18025"

        YAML;

    private function typesenseAndMail(): void
    {
        $this->catalogue(new NamedPort('typesense', 9108, 8108), new NamedPort('mail', 9200, 18025));
        $this->globalConfig("router_https_port: 8443\n");
    }

    public function testBothFilesAreWrittenByteForByte(): void
    {
        $this->typesenseAndMail();

        $result = $this->mirror->sync();

        self::assertTrue($result->changed);
        self::assertSame([443, 9108, 9200], $result->mirrored);
        self::assertSame([], $result->skipped);
        self::assertSame(self::GOLDEN_STATIC, $this->read($this->traefik->staticFile()));
        self::assertSame(self::GOLDEN_DYNAMIC, $this->read($this->traefik->dynamicFile()));
        self::assertSame(0o644, fileperms($this->traefik->staticFile()) & 0o777);
        self::assertSame($this->userHome.'/.ddev/traefik/static_config.fleet-port-mirror.yaml', $this->traefik->staticFile());
        self::assertSame($this->userHome.'/.ddev/traefik/custom-global-config/fleet-port-mirror.yaml', $this->traefik->dynamicFile());
    }

    public function testWithoutASiteMirrorThereIsNoFourFortyThreeEntry(): void
    {
        $this->catalogue(new NamedPort('typesense', 9108, 8108));

        $this->mirror->sync();

        self::assertSame("# Managed by ddev-fleet: do not edit.\nentryPoints:\n  fleet-mirror-9108:\n    address: \":9108\"\n", $this->read($this->traefik->staticFile()));
        self::assertStringNotContainsString('443', $this->read($this->traefik->dynamicFile()));
    }

    public function testASecondSyncWritesNothing(): void
    {
        $this->typesenseAndMail();
        $this->mirror->sync();
        $files = [$this->traefik->staticFile(), $this->traefik->dynamicFile()];
        $before = [];
        foreach ($files as $file) {
            touch($file, 1_000_000_000);
            clearstatcache(true, $file);
            $before[$file] = [fileinode($file), filemtime($file)];
        }

        $again = $this->mirror->sync();

        self::assertFalse($again->changed);
        self::assertSame([443, 9108, 9200], $again->mirrored);
        foreach ($files as $file) {
            clearstatcache(true, $file);
            self::assertSame($before[$file], [fileinode($file), filemtime($file)], 'the file was rewritten');
        }
        self::assertSame([], glob($this->traefik->dir().'/.*tmp') ?: [], 'no temp file left behind');
    }

    public function testAChangedCatalogueRewritesTheFiles(): void
    {
        $this->typesenseAndMail();
        $this->mirror->sync();
        $this->catalogue(new NamedPort('search', 9300, 8300));

        $result = $this->mirror->sync();

        self::assertTrue($result->changed);
        self::assertSame([443, 9108, 9200, 9300], $result->mirrored);
        self::assertStringContainsString('fleet-mirror-9300', $this->read($this->traefik->staticFile()));
    }

    public function testNothingToMirrorDeletesTheFiles(): void
    {
        $this->typesenseAndMail();
        $this->mirror->sync();
        foreach ($this->ports->all() as $port) {
            $this->ports->remove($port);
        }
        // The site mirror alone is still something to mirror.
        self::assertSame([443], $this->mirror->sync()->mirrored);

        $this->globalConfig("router_https_port: 443\n");
        $result = $this->mirror->sync();

        self::assertTrue($result->changed);
        self::assertSame([], $result->mirrored);
        self::assertFileDoesNotExist($this->traefik->staticFile());
        self::assertFileDoesNotExist($this->traefik->dynamicFile());
        self::assertFalse($this->mirror->sync()->changed, 'deleting twice is a no-op');
    }

    public function testSwitchingTheMirrorOffDeletesTheFilesAndTheSiteEntry(): void
    {
        $this->typesenseAndMail();
        $this->mirror->sync();
        self::assertTrue($this->mirror->enabled());

        $this->switchMirror(false);
        $result = $this->mirror->sync();

        self::assertFalse($this->mirror->enabled());
        self::assertTrue($result->changed);
        self::assertSame([], $result->mirrored);
        self::assertSame([], $result->skipped);
        self::assertFileDoesNotExist($this->traefik->staticFile());
        self::assertFileDoesNotExist($this->traefik->dynamicFile());

        $this->switchMirror(true);
        self::assertSame([443, 9108, 9200], $this->mirror->sync()->mirrored);
    }

    public function testNoTraefikDirectoryMeansNothingIsWritten(): void
    {
        $this->typesenseAndMail();
        self::removeDir($this->traefik->dir());

        $result = $this->mirror->sync();

        self::assertFalse($result->changed);
        self::assertSame([], $result->mirrored);
        self::assertStringContainsString('does not exist', $result->skipped[0]);
        self::assertDirectoryDoesNotExist($this->traefik->dir());
    }

    public function testTheFailedMarkerBlocksSyncWhileItMatchesAndAnotherSetClearsIt(): void
    {
        $this->typesenseAndMail();
        $this->files->writeMarker([9200, 443, 9108]);
        self::assertSame("443,9108,9200\n", $this->read($this->traefik->markerFile()));

        $blocked = $this->mirror->sync();

        self::assertFalse($blocked->changed);
        self::assertSame([], $blocked->mirrored);
        self::assertStringContainsString('a previous apply failed', $blocked->skipped[0]);
        self::assertFileDoesNotExist($this->traefik->staticFile());

        $this->catalogue(new NamedPort('search', 9300, 8300));
        $changed = $this->mirror->sync();

        self::assertSame([443, 9108, 9200, 9300], $changed->mirrored);
        self::assertFileDoesNotExist($this->traefik->markerFile(), 'a different wanted set clears the marker');
    }

    public function testClearFailedMarkerIsTheExplicitRetry(): void
    {
        $this->typesenseAndMail();
        $this->files->writeMarker([443, 9108, 9200]);

        self::assertTrue($this->mirror->clearFailedMarker());
        self::assertFalse($this->mirror->clearFailedMarker());
        self::assertSame([443, 9108, 9200], $this->mirror->sync()->mirrored);
    }

    public function testAMarkerForNothingIsRemovedWhenNothingIsWanted(): void
    {
        $this->files->writeMarker([9108]);

        $this->mirror->sync();

        self::assertFileDoesNotExist($this->traefik->markerFile());
    }

    public function testIsLiveComparesTheRouterWithTheWantedSet(): void
    {
        $this->typesenseAndMail();
        $this->foreign('static_config.spike.yaml', "entryPoints:\n  fleet-mirror-9999:\n    address: \":9999\"\n");

        $this->live->script(null);
        self::assertFalse($this->mirror->isLive(), 'unreachable with something wanted');
        $this->live->script(['http-80', 'fleet-mirror-443', 'fleet-mirror-9108']);
        self::assertFalse($this->mirror->isLive());
        $this->live->script(['http-80', 'fleet-mirror-443', 'fleet-mirror-9108', 'fleet-mirror-9200', 'fleet-mirror-9999']);
        self::assertTrue($this->mirror->isLive(), 'a foreign fleet-mirror-* name is not ours');

        $this->switchMirror(false);
        $this->live->script(null);
        self::assertTrue($this->mirror->isLive(), 'unreachable with nothing wanted');
        $this->live->script(['http-80', 'fleet-mirror-9108']);
        self::assertFalse($this->mirror->isLive(), 'a stale entrypoint is still live');
    }

    private static function removeDir(string $dir): bool
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        return rmdir($dir);
    }
}
