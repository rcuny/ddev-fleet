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
        yield 'DDEV variable then dotdot' => ["services:\n  web:\n    volumes: ['\${DDEV_APPROOT}/../../..:/x']\n", 'services.web.volumes[0]'];
        yield 'DDEV variable with default' => ["services:\n  web:\n    volumes: ['\${DDEV_APPROOT:-/etc}:/x']\n", 'services.web.volumes[0]'];
        yield 'unlisted DDEV variable' => ["services:\n  web:\n    volumes: ['\${DDEV_GLOBAL_DIR}/x:/x']\n", 'services.web.volumes[0]'];
        yield 'include' => ["include:\n  - other.yaml\nservices: {}\n", 'include'];
        yield 'extends file' => ["services:\n  web:\n    extends: {file: /etc/x.yaml, service: a}\n", 'services.web.extends.file'];
        yield 'volumes_from container' => ["services:\n  web:\n    volumes_from: ['container:other']\n", 'services.web.volumes_from[0]'];
        yield 'services is a scalar' => ["services: nope\n", 'services'];
        yield 'service is a scalar' => ["services:\n  web: nope\n", 'services.web'];
        yield 'volumes is a scalar' => ["volumes: nope\n", 'volumes'];
        yield 'service volumes is a scalar' => ["services:\n  web:\n    volumes: /etc:/x\n", 'services.web.volumes'];
        yield 'network of a container' => ["services:\n  web:\n    network_mode: 'container:abc'\n", 'services.web.network_mode'];
        yield 'network of a service' => ["services:\n  web:\n    network_mode: 'service:db'\n", 'services.web.network_mode'];
        yield 'pid of a container' => ["services:\n  web:\n    pid: 'container:abc'\n", 'services.web.pid'];
        yield 'cgroup host' => ["services:\n  web:\n    cgroup: host\n", 'services.web.cgroup'];
        yield 'cgroup_parent' => ["services:\n  web:\n    cgroup_parent: system.slice\n", 'services.web.cgroup_parent'];
        yield 'sysctls' => ["services:\n  web:\n    sysctls: {net.ipv4.ip_forward: 1}\n", 'services.web.sysctls'];
        yield 'label disable' => ["services:\n  web:\n    security_opt: ['label=disable']\n", 'services.web.security_opt[0]'];
        yield 'no-new-privileges false' => ["services:\n  web:\n    security_opt: ['no-new-privileges:false']\n", 'services.web.security_opt[0]'];
        yield 'socket as a mapping key' => ["services:\n  web:\n    labels:\n      /var/run/docker.sock: x\n", 'services.web.labels./var/run/docker.sock'];
        yield 'anchor and merge' => ["x-evil: &evil\n  privileged: true\nservices:\n  web:\n    <<: *evil\n", 'services.web.privileged'];
        yield 'bind driver volume' => ["volumes:\n  v:\n    driver_opts: {type: none, o: bind, device: /etc}\n", 'volumes.v.driver_opts.device'];
    }

    #[DataProvider('refused')]
    public function testItRefusesWhatGivesTheContainerTheHost(string $yaml, string $key): void
    {
        self::assertSame(['.ddev/docker-compose.evil.yaml '.$key], $this->scan('docker-compose.evil.yaml', $yaml));
    }

    public function testFleetsOwnNetworkOverrideWithItsTagIsAccepted(): void
    {
        self::assertSame([], $this->scan('docker-compose.zzz-fleet-isolation.yaml', "services:\n  db:\n    networks: !override\n      default: {}\n"));
    }

    public function testATagDoesNotHideAViolation(): void
    {
        $found = $this->scan('docker-compose.override.yaml', "services:\n  web: !override\n    network_mode: host\n    pid: 'host'\n    cgroup: host\n");

        self::assertNotSame([], $found);
        self::assertTrue([] !== array_filter($found, static fn (string $f): bool => str_contains($f, 'cgroup')), implode("\n", $found));
    }

    public function testASocketChmodInAConfigHookIsRefusedWithItsKeyPath(): void
    {
        $found = $this->scan('config.dockersock.yaml', "hooks:\n  post-start:\n    - exec-host: sudo chmod 666 /var/run/docker.sock\n");

        self::assertSame(['.ddev/config.dockersock.yaml hooks.post-start[0].exec-host'], $found);
    }

    public function testAnExecHostHookIsRefusedInAnyConfigFile(): void
    {
        self::assertSame(['.ddev/config.x.yaml hooks.post-start[0].exec-host'], $this->scan('config.x.yaml', "hooks:\n  post-start:\n    - exec-host: id\n"));
        unlink($this->dir.'/.ddev/config.x.yaml');
        self::assertSame(['.ddev/config.y.yaml hooks.pre-start[0].exec_host'], $this->scan('config.y.yaml', "hooks:\n  pre-start:\n    - exec_host: id\n"));
    }

    public function testASymlinkedSubdirectoryCannotLeadAnAllowedPathOutOfTheCheckout(): void
    {
        symlink('/etc', $this->dir.'/escape');
        $found = $this->scan('docker-compose.l.yaml', "services:\n  web:\n    volumes: ['\${DDEV_APPROOT}/escape/ssl:/x', '../escape:/y']\n");
        unlink($this->dir.'/escape');

        self::assertSame(['.ddev/docker-compose.l.yaml services.web.volumes[0]', '.ddev/docker-compose.l.yaml services.web.volumes[1]'], $found);
    }

    public function testASymlinkedDdevDirectoryIsRefused(): void
    {
        $other = sys_get_temp_dir().'/guard-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0o777, true);
        $dir = sys_get_temp_dir().'/guard-link-'.bin2hex(random_bytes(4));
        mkdir($dir);
        symlink($other, $dir.'/.ddev');
        $found = array_map(static fn ($v): string => $v->file.' '.$v->key, new ComposeOverrideGuard()->scan($dir));
        unlink($dir.'/.ddev');
        rmdir($dir);
        rmdir($other);

        self::assertSame(['.ddev (directory)'], $found);
    }

    public function testADirectoryNamedLikeAComposeFileIsRefused(): void
    {
        mkdir($this->dir.'/.ddev/docker-compose.dir.yaml');
        $found = array_map(static fn ($v): string => $v->file.' '.$v->key, new ComposeOverrideGuard()->scan($this->dir));
        rmdir($this->dir.'/.ddev/docker-compose.dir.yaml');

        self::assertSame(['.ddev/docker-compose.dir.yaml (file)'], $found);
    }

    public function testOnlyFleetsOwnSliceOverrideMayChooseACgroupParent(): void
    {
        $yaml = "services:\n  web:\n    cgroup_parent: fleet-t.slice\n";

        self::assertSame([], $this->scan('docker-compose.fleet-cgroup.yaml', $yaml));
        self::assertSame(['.ddev/docker-compose.other.yaml services.web.cgroup_parent'], $this->scan('docker-compose.other.yaml', $yaml));
        unlink($this->dir.'/.ddev/docker-compose.other.yaml');
        self::assertSame(['.ddev/docker-compose.fleet-cgroup.yaml services.web.cgroup_parent'], $this->scan('docker-compose.fleet-cgroup.yaml', "services:\n  web:\n    cgroup_parent: /docker/x\n"));
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

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function webBuild(): iterable
    {
        yield 'docker.io' => ['Dockerfile', "FROM x\nRUN apt-get install -y curl docker.io\n", ['.ddev/web-build/Dockerfile docker-cli']];
        yield 'docker-ce-cli, continued line' => ['Dockerfile.web', "RUN apt-get install -y \\\n  git docker-ce-cli\n", ['.ddev/web-build/Dockerfile.web docker-cli']];
        yield 'socket' => ['pre.Dockerfile', "RUN ln -s /x /var/run/docker.sock\n", ['.ddev/web-build/pre.Dockerfile docker.sock']];
        yield 'chmod u+s' => ['Dockerfile', "RUN chmod u+s /usr/bin/find\n", ['.ddev/web-build/Dockerfile chmod']];
        yield 'chmod 4755' => ['Dockerfile', "RUN chmod 4755 /bin/x\n", ['.ddev/web-build/Dockerfile chmod']];
        yield 'ordinary build is fine' => ['Dockerfile', "RUN curl -fsSL https://x.example/i.sh | sh\nADD https://x.example/a.tgz /opt/\nRUN chmod 755 /x && chmod +x /y && apt-get install -y dockerize\n", []];
        yield 'fleet fragment exempt' => ['Dockerfile.fleet-mailpit-healthcheck', "RUN chmod u+s /x docker.io\n", []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('webBuild')]
    public function testWebBuildFiles(string $name, string $content, array $expected): void
    {
        mkdir($this->dir.'/.ddev/web-build');
        file_put_contents($this->dir.'/.ddev/web-build/'.$name, $content);
        $got = array_map(static fn ($v): string => $v->file.' '.$v->key, new ComposeOverrideGuard()->scan($this->dir));
        unlink($this->dir.'/.ddev/web-build/'.$name);
        rmdir($this->dir.'/.ddev/web-build');
        self::assertSame($expected, $got);
    }
}
