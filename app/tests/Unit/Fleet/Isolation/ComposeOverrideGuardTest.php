<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Fleet\Isolation\ComposeOverrideGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposeOverrideGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/guard-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.ddev', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/.ddev/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir.'/.ddev');
        rmdir($this->dir);
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string> "file key" of every violation
     */
    private function scan(string $file, string $yaml, array $allowed = []): array
    {
        file_put_contents($this->dir.'/.ddev/'.$file, $yaml);

        return array_map(static fn ($v): string => $v->file.' '.$v->key, new ComposeOverrideGuard()->scan($this->dir, $allowed));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'a socket bridge' => ["services:\n  web:\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n", 'services.web.volumes[0]'];
        yield 'socket, long syntax' => ["services:\n  web:\n    volumes:\n      - {type: bind, source: /run/docker.sock, target: /x}\n", 'services.web.volumes[0].source'];
        yield 'privileged' => ["services:\n  web:\n    privileged: true\n", 'services.web.privileged'];
        yield 'network host' => ["services:\n  web:\n    network_mode: host\n", 'services.web.network_mode'];
        yield 'pid host' => ["services:\n  web:\n    pid: host\n", 'services.web.pid'];
        yield 'ipc host' => ["services:\n  web:\n    ipc: host\n", 'services.web.ipc'];
        yield 'SYS_ADMIN' => ["services:\n  web:\n    cap_add: [SYS_ADMIN]\n", 'services.web.cap_add[0]'];
        yield 'CAP_NET_ADMIN' => ["services:\n  web:\n    cap_add: [CAP_NET_ADMIN]\n", 'services.web.cap_add[0]'];
        yield 'ALL' => ["services:\n  web:\n    cap_add: [all]\n", 'services.web.cap_add[0]'];
        yield 'devices' => ["services:\n  web:\n    devices: ['/dev/kvm:/dev/kvm']\n", 'services.web.devices'];
        yield 'seccomp off' => ["services:\n  web:\n    security_opt: ['seccomp=unconfined']\n", 'services.web.security_opt[0]'];
        yield 'host root' => ["services:\n  web:\n    volumes: ['/:/host']\n", 'services.web.volumes[0]'];
        yield 'home dir' => ["services:\n  web:\n    volumes: ['~/.ssh:/ssh']\n", 'services.web.volumes[0]'];
        yield 'dotdot escape' => ["services:\n  web:\n    volumes: ['../../other:/o']\n", 'services.web.volumes[0]'];
        yield 'unknown variable' => ["services:\n  web:\n    volumes: ['\${HOME}/x:/x']\n", 'services.web.volumes[0]'];
        yield 'bind driver volume' => ["volumes:\n  v:\n    driver_opts: {type: none, o: bind, device: /etc}\n", 'volumes.v.driver_opts.device'];
    }

    #[DataProvider('refused')]
    public function testItRefusesWhatGivesTheContainerTheHost(string $yaml, string $key): void
    {
        self::assertSame(['.ddev/docker-compose.evil.yaml '.$key], $this->scan('docker-compose.evil.yaml', $yaml));
    }

    public function testASocketChmodInAConfigHookIsRefusedWithItsKeyPath(): void
    {
        $found = $this->scan('config.dockersock.yaml', "hooks:\n  post-start:\n    - exec-host: sudo chmod 666 /var/run/docker.sock\n");

        self::assertSame(['.ddev/config.dockersock.yaml hooks.post-start[0].exec-host'], $found);
    }

    public function testOrdinaryOverridesPass(): void
    {
        $ok = <<<'YAML'
            services:
              typesense:
                image: typesense/typesense:27
                volumes:
                  - typesense-data:/data
                  - ./typesense-conf:/conf:ro
                  - ../files:/files
                  - ${DDEV_APPROOT}/data:/d
                  - /var/lib/anonymous
                cap_add: [SYS_PTRACE]
                environment: {A: b}
            volumes:
              typesense-data: {}
            YAML;
        self::assertSame([], $this->scan('docker-compose.typesense.yaml', $ok));
        self::assertSame([], $this->scan('config.local.yaml', "name: blog\nhooks:\n  post-start:\n    - exec: drush cr\n"));
    }

    public function testTheInstancesOwnAgentSocketDirectoryIsAllowedButNotAnotherOne(): void
    {
        $yaml = "services:\n  web:\n    volumes: ['/run/fleet/a.agent:/run/fleet-ssh-agent']\n";

        self::assertSame([], $this->scan('docker-compose.fleet-ssh.yaml', $yaml, ['/run/fleet/a.agent']));
        self::assertCount(1, $this->scan('docker-compose.fleet-ssh.yaml', $yaml, ['/run/fleet/b.agent']));
    }

    public function testAnUnparsableFileAndASymlinkCannotBeVerifiedAndAreRefused(): void
    {
        self::assertSame(['.ddev/docker-compose.bad.yaml (file)'], $this->scan('docker-compose.bad.yaml', "services: [unclosed\n"));
        unlink($this->dir.'/.ddev/docker-compose.bad.yaml');
        symlink('/etc/hostname', $this->dir.'/.ddev/docker-compose.link.yaml');
        self::assertSame(['.ddev/docker-compose.link.yaml (file)'], array_map(static fn ($v): string => $v->file.' '.$v->key, new ComposeOverrideGuard()->scan($this->dir)));
    }

    public function testFilesOutsideDdevAreNotScanned(): void
    {
        file_put_contents($this->dir.'/docker-compose.yaml', "services:\n  web:\n    privileged: true\n");
        self::assertSame([], new ComposeOverrideGuard()->scan($this->dir));
        unlink($this->dir.'/docker-compose.yaml');
    }
}
