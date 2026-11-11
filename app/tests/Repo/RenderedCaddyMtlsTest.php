<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateMode;
use App\Fleet\System\GateWriter;
use App\Tests\Support\FixedOvhEndpoint;
use App\Tests\Support\SystemTemplates;
use PHPUnit\Framework\TestCase;

/**
 * FLE-289: the opt-in client-certificate requirement on /api/v1/* against a REAL Caddy, over real TLS. Same harness as
 * RenderedCaddyApiRouteTest (adapted Caddyfile, FastCGI echo upstream, loopback port), but the listener keeps its TLS
 * connection policy and serves a throw-away certificate, and the client presents certificates made with openssl.
 * Proves the `@nocert` placeholder `{http.request.tls.client.subject}` is empty without a certificate and set with one.
 * Skips without a Caddy (FLEET_CADDY_STOCK_BIN / FLEET_CADDY_BIN) unless FLEET_REQUIRE_CADDY=1 (CI).
 */
final class RenderedCaddyMtlsTest extends TestCase
{
    private string $tree;
    private string $bin;
    /** @var array<string, string> */
    private array $env;
    /** @var resource|false|null */
    private $caddyProc;
    /** @var resource|false|null */
    private $upstreamProc;
    private int $port;

    protected function setUp(): void
    {
        $bin = getenv('FLEET_CADDY_STOCK_BIN') ?: getenv('FLEET_CADDY_BIN');
        if (false === $bin || '' === $bin || !is_executable($bin)) {
            if ('' !== (getenv('FLEET_REQUIRE_CADDY') ?: '') && '0' !== getenv('FLEET_REQUIRE_CADDY')) {
                self::fail('FLEET_REQUIRE_CADDY is set but no executable Caddy is available (FLEET_CADDY_STOCK_BIN / FLEET_CADDY_BIN).');
            }
            self::markTestSkipped('Neither FLEET_CADDY_STOCK_BIN nor FLEET_CADDY_BIN is set to an executable Caddy.');
        }
        $this->bin = $bin;
        $this->tree = sys_get_temp_dir().'/fleet-caddy-mtls-'.bin2hex(random_bytes(6));
        mkdir($this->tree.'/etc/caddy/fleet', 0o700, true);
        mkdir($this->tree.'/project/public', 0o700, true);
        mkdir($this->tree.'/home', 0o700, true);
        mkdir($this->tree.'/pki', 0o700, true);
        $this->env = [
            'HOME' => $this->tree.'/home',
            'XDG_CONFIG_HOME' => $this->tree.'/home/config',
            'XDG_DATA_HOME' => $this->tree.'/home/data',
            'FLEET_PROXY_SECRET' => 'proxy-secret-0123456789abcdef-0123456789',
        ];
    }

    protected function tearDown(): void
    {
        foreach ([$this->caddyProc ?? null, $this->upstreamProc ?? null] as $proc) {
            if (\is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
        if (!isset($this->tree) || !is_dir($this->tree)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tree, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tree);
    }

    public function testOffByDefaultNoCertificateIsAskedForAndTheApiAnswers(): void
    {
        $adapted = $this->start(false);

        self::assertStringNotContainsString('client_authentication', $adapted, 'no tls block without the CA file');
        $response = $this->request('/api/v1/tokens/self', null);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('"params"', $response['body']);
    }

    public function testApiRefusesACallWithoutAClientCertificate(): void
    {
        $this->start(true);

        $response = $this->request('/api/v1/tokens/self', null);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('A client certificate is required.', $response['body']);
        self::assertStringNotContainsString('"params"', $response['body'], 'the request never reached the app');
    }

    public function testApiAcceptsACallWithACertificateSignedByTheConfiguredCa(): void
    {
        $this->start(true);

        $response = $this->request('/api/v1/tokens/self', $this->tree.'/pki/good.pem');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('"params"', $response['body'], 'the request reached the app');
    }

    public function testTheUiAndTheWebhooksStillWorkWithoutACertificate(): void
    {
        $this->start(true);

        // /health and /hooks/* bypass the login gate and reach the app: no certificate needed.
        self::assertSame(200, $this->request('/health', null)['status']);
        self::assertSame(200, $this->request('/hooks/jira', null)['status']);
        // The UI is behind the login gate (a closed port here): the handshake works and the answer is the gate's, never the certificate refusal.
        $ui = $this->request('/', null);
        self::assertNotSame(200, $ui['status']);
        self::assertStringNotContainsString('A client certificate is required.', $ui['body']);
        self::assertNotSame(0, $ui['status'], 'the handshake completed');
    }

    public function testACertificateThatFailsVerificationNeverGetsAnAnswer(): void
    {
        $this->start(true);

        $response = $this->request('/api/v1/tokens/self', $this->tree.'/pki/rogue.pem');

        self::assertSame(0, $response['status'], 'the handshake is refused, no HTTP answer: '.$response['body']);
    }

    /** Builds the PKI, renders and adapts the Caddyfile (mTLS on or off), starts the upstream and Caddy. Returns the adapted JSON. */
    private function start(bool $mtls): string
    {
        $pki = $this->tree.'/pki';
        $this->openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $pki.'/ca.key', '-out', $pki.'/ca.pem', '-subj', '/CN=Fleet Test API CA', '-days', '2']);
        $this->openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $pki.'/other.key', '-out', $pki.'/other.pem', '-subj', '/CN=Rogue CA', '-days', '2']);
        $this->openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $pki.'/srv.key', '-out', $pki.'/srv.pem', '-subj', '/CN=fleet.example.com', '-addext', 'subjectAltName=DNS:fleet.example.com', '-days', '2']);
        $this->clientCertificate('good', 'ca');
        $this->clientCertificate('rogue', 'other');

        $paths = new FleetPaths($this->tree.'/srv', $this->tree.'/etc/caddy/fleet');
        if ($mtls) {
            copy($pki.'/ca.pem', $paths->apiClientCaFile());
        }
        $host = new HostSettings('fleet.example.com', new \DateTimeImmutable());
        $host->changeTlsMode(TlsMode::Http01, new \DateTimeImmutable());
        $host->changeAcmeEmail('ops@example.com', new \DateTimeImmutable());
        $twig = SystemTemplates::twig();
        file_put_contents($paths->caddyMainFile(), new CaddyfileRenderer($twig, $paths, new FixedOvhEndpoint(), $this->tree.'/project')->render($host));
        file_put_contents($paths->gateFile(), new GateWriter($twig, $paths, new CaddyReloader(new RecordingCommandRunner(), $paths))->render(GateMode::Authelia));

        $adapted = new ProcessCommandRunner()->run([$this->bin, 'adapt', '--config', $paths->caddyMainFile(), '--adapter', 'caddyfile'], env: $this->env, timeout: 60.0);
        self::assertSame(0, $adapted->exitCode, $adapted->stderr);

        $this->port = self::freePort();
        $socket = $this->tree.'/php.sock';
        $json = str_replace('unix//run/php/fleet.sock', 'unix/'.$socket, $adapted->stdout);
        $config = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $config['admin'] = ['listen' => 'unix/'.$this->tree.'/admin.sock'];
        foreach (array_keys($config['apps']['http']['servers']) as $name) {
            $server = &$config['apps']['http']['servers'][$name];
            $server['listen'] = ['127.0.0.1:'.$this->port];
            $server['automatic_https'] = ['disable' => true];
            // Without the opt-in the adapted server has no connection policy (Caddy would serve plain HTTP): keep TLS on for the comparison.
            if (empty($server['tls_connection_policies'])) {
                $server['tls_connection_policies'] = [[]];
            }
            // json_decode(assoc) turns an empty policy object into an empty list; Caddy wants an object.
            $server['tls_connection_policies'] = array_map(static fn (array $policy): array|\stdClass => [] === $policy ? new \stdClass() : $policy, $server['tls_connection_policies']);
            unset($server);
        }
        $config['apps']['tls'] = ['certificates' => ['load_files' => [['certificate' => $pki.'/srv.pem', 'key' => $pki.'/srv.key']]]];
        unset($config['logging']);
        file_put_contents($this->tree.'/caddy.json', json_encode($config, \JSON_THROW_ON_ERROR));

        file_put_contents($this->tree.'/upstream.php', RenderedCaddyApiRouteTest::upstreamScript());
        $this->upstreamProc = proc_open([\PHP_BINARY, $this->tree.'/upstream.php', $socket], [2 => ['file', $this->tree.'/upstream.err', 'w']], $pipes, null, null);
        $this->caddyProc = proc_open([$this->bin, 'run', '--config', $this->tree.'/caddy.json'], [1 => ['file', $this->tree.'/caddy.out', 'w'], 2 => ['file', $this->tree.'/caddy.err', 'w']], $pipes, null, $this->env);

        for ($i = 0; $i < 100; ++$i) {
            if (file_exists($socket) && false !== ($c = @fsockopen('127.0.0.1', $this->port))) {
                fclose($c);

                return $adapted->stdout;
            }
            usleep(100_000);
        }
        self::fail('Caddy did not start: '.@file_get_contents($this->tree.'/caddy.err'));
    }

    /** A client certificate for $name signed by the CA named $ca; written as one PEM (certificate then key). */
    private function clientCertificate(string $name, string $ca): void
    {
        $pki = $this->tree.'/pki';
        file_put_contents($pki.'/client.ext', "extendedKeyUsage=clientAuth\n");
        $this->openssl(['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', $pki.'/'.$name.'.key', '-out', $pki.'/'.$name.'.csr', '-subj', '/CN=orchestrator-'.$name]);
        $this->openssl(['x509', '-req', '-in', $pki.'/'.$name.'.csr', '-CA', $pki.'/'.$ca.'.pem', '-CAkey', $pki.'/'.$ca.'.key', '-CAcreateserial', '-out', $pki.'/'.$name.'.crt', '-days', '2', '-extfile', $pki.'/client.ext']);
        file_put_contents($pki.'/'.$name.'.pem', file_get_contents($pki.'/'.$name.'.crt').file_get_contents($pki.'/'.$name.'.key'));
    }

    /** @param list<string> $args */
    private function openssl(array $args): void
    {
        $result = new ProcessCommandRunner()->run(['openssl', ...$args], timeout: 60.0);
        self::assertSame(0, $result->exitCode, $result->stderr);
    }

    /** @return array{status: int, body: string} status 0 = no HTTP answer (the handshake or the connection was refused) */
    private function request(string $path, ?string $clientPem): array
    {
        $options = ['verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => 'fleet.example.com', 'SNI_enabled' => true, 'SNI_server_name' => 'fleet.example.com'];
        if (null !== $clientPem) {
            $options['local_cert'] = $clientPem;
        }
        $socket = @stream_socket_client('tls://127.0.0.1:'.$this->port, $errno, $error, 5.0, \STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $options]));
        if (false === $socket) {
            return ['status' => 0, 'body' => 'connect: '.$error];
        }
        stream_set_timeout($socket, 10);
        // TLS 1.3 reports a refused client certificate on the first read, not at connect: both end in no HTTP answer.
        @fwrite($socket, 'GET '.$path." HTTP/1.1\r\nHost: fleet.example.com\r\nConnection: close\r\n\r\n");
        $response = (string) @stream_get_contents($socket);
        fclose($socket);

        if (1 !== preg_match('#^HTTP/\S+ (\d{3})#', $response, $m)) {
            return ['status' => 0, 'body' => $response];
        }
        $parts = explode("\r\n\r\n", $response, 2);

        return ['status' => (int) $m[1], 'body' => $parts[1] ?? ''];
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, (string) $error);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
