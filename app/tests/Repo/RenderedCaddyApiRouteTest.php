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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FLE-274 (SR2): the /api/v1/* bypass against a REAL Caddy. The rendered Caddyfile (Authelia gate) is adapted to JSON,
 * the PHP socket is pointed at a tiny FastCGI responder that echoes the parameters it received, the listener is moved
 * to a high loopback port without TLS, and requests are sent over a raw socket so the exact header spelling is ours.
 * Needs FLEET_CADDY_STOCK_BIN (a stock Caddy, see ci/fetch-caddy.sh) or, failing that, FLEET_CADDY_BIN; skips without one.
 */
final class RenderedCaddyApiRouteTest extends TestCase
{
    private string $tree;
    /** @var resource|false|null */
    private $caddyProc;
    /** @var resource|false|null */
    private $upstreamProc;
    private int $port;

    protected function setUp(): void
    {
        $bin = getenv('FLEET_CADDY_STOCK_BIN') ?: getenv('FLEET_CADDY_BIN');
        if (false === $bin || '' === $bin || !is_executable($bin)) {
            self::markTestSkipped('Neither FLEET_CADDY_STOCK_BIN nor FLEET_CADDY_BIN is set to an executable Caddy.');
        }
        $this->tree = sys_get_temp_dir().'/fleet-caddy-api-'.bin2hex(random_bytes(6));
        mkdir($this->tree.'/etc/caddy/fleet', 0o700, true);
        mkdir($this->tree.'/project/public', 0o700, true);
        mkdir($this->tree.'/home', 0o700, true);

        $paths = new FleetPaths($this->tree.'/srv', $this->tree.'/etc/caddy/fleet');
        $host = new HostSettings('fleet.example.com', new \DateTimeImmutable());
        $host->changeTlsMode(TlsMode::Http01, new \DateTimeImmutable());
        $host->changeAcmeEmail('ops@example.com', new \DateTimeImmutable());
        $twig = SystemTemplates::twig();
        file_put_contents($paths->caddyMainFile(), new CaddyfileRenderer($twig, $paths, new FixedOvhEndpoint(), $this->tree.'/project')->render($host));
        file_put_contents($paths->gateFile(), new GateWriter($twig, $paths, new CaddyReloader(new RecordingCommandRunner(), $paths))->render(GateMode::Authelia));

        $env = [
            'HOME' => $this->tree.'/home',
            'XDG_CONFIG_HOME' => $this->tree.'/home/config',
            'XDG_DATA_HOME' => $this->tree.'/home/data',
            'FLEET_PROXY_SECRET' => 'proxy-secret-0123456789abcdef-0123456789',
        ];
        $adapted = new ProcessCommandRunner()->run([$bin, 'adapt', '--config', $paths->caddyMainFile(), '--adapter', 'caddyfile'], env: $env, timeout: 60.0);
        self::assertSame(0, $adapted->exitCode, $adapted->stderr);

        $this->port = self::freePort();
        $socket = $this->tree.'/php.sock';
        $json = str_replace('unix//run/php/fleet.sock', 'unix/'.$socket, $adapted->stdout);
        $config = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $config['admin'] = ['listen' => 'unix/'.$this->tree.'/admin.sock'];
        foreach (array_keys($config['apps']['http']['servers']) as $name) {
            $config['apps']['http']['servers'][$name]['listen'] = ['127.0.0.1:'.$this->port];
            $config['apps']['http']['servers'][$name]['automatic_https'] = ['disable' => true];
        }
        unset($config['apps']['tls'], $config['logging']);
        file_put_contents($this->tree.'/caddy.json', json_encode($config, \JSON_THROW_ON_ERROR));

        file_put_contents($this->tree.'/upstream.php', self::upstreamScript());
        $this->upstreamProc = proc_open([\PHP_BINARY, $this->tree.'/upstream.php', $socket], [2 => ['file', $this->tree.'/upstream.err', 'w']], $pipes, null, null);
        $this->caddyProc = proc_open([$bin, 'run', '--config', $this->tree.'/caddy.json'], [1 => ['file', $this->tree.'/caddy.out', 'w'], 2 => ['file', $this->tree.'/caddy.err', 'w']], $pipes, null, $env);

        for ($i = 0; $i < 100; ++$i) {
            if (file_exists($socket) && false !== ($c = @fsockopen('127.0.0.1', $this->port))) {
                fclose($c);

                return;
            }
            usleep(100_000);
        }
        self::fail('Caddy did not start: '.@file_get_contents($this->tree.'/caddy.err'));
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

    public function testForgedIdentityHeadersNeverReachTheApiUpstream(): void
    {
        $forged = [
            'Remote-User: admin', 'Remote_User: admin', 'remote-user: admin', 'REMOTE_USER: admin',
            'Remote-Groups: fleet-admins', 'Remote_Groups: fleet-admins', 'Remote-Name: Mallory', 'Remote_Name: Mallory',
            'Remote-Email: m@example.com', 'Remote_Email: m@example.com',
        ];
        foreach (['/api/v1/tokens/self', '/api/v1/tokens/self?x=1'] as $path) {
            $response = $this->request('GET', $path, $forged + ['Authorization' => 'Authorization: Bearer flt_svc_x']);

            self::assertSame(200, $response['status'], $path.' must reach the app, the login gate is bypassed');
            $params = $response['json']['params'];
            foreach (array_keys($params) as $name) {
                self::assertDoesNotMatchRegularExpression('/^HTTP_REMOTE_/i', (string) $name, 'a forged identity header reached PHP: '.$name);
            }
            self::assertSame('proxy-secret-0123456789abcdef-0123456789', $params['FLEET_PROXY_AUTH'] ?? null, 'the FastCGI parameter is set by Caddy');
            self::assertSame('Bearer flt_svc_x', $params['HTTP_AUTHORIZATION'] ?? null, 'the bearer header passes through untouched');
        }
    }

    public function testTheApiResponseIsNeverCachedAndCarriesNoCors(): void
    {
        $response = $this->request('GET', '/api/v1/tokens/self', []);

        // The test upstream answers with `Cache-Control: public, max-age=3600`; Caddy must replace it, not add to it.
        self::assertSame('no-store', $response['headers']['cache-control'] ?? null);
        self::assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
    }

    public function testBodiesOverOneMegabyteAreRefusedBeforeTheAppSeesThem(): void
    {
        $small = $this->request('POST', '/api/v1/tokens/self', [], str_repeat('a', 1024));
        self::assertSame(200, $small['status']);

        $big = $this->request('POST', '/api/v1/tokens/self', [], str_repeat('a', 1024 * 1024 + 1));
        self::assertSame(413, $big['status']);
    }

    public function testOnlyApiV1SkipsTheLoginGate(): void
    {
        // The Authelia gate points at a closed port here: a gated path ends in a gateway error and never reaches PHP.
        foreach (['/admin', '/api/other', '/apiv1', '/api/v10/x', '/'] as $path) {
            $response = $this->request('GET', $path, ['Remote-User: admin']);

            self::assertNotSame(200, $response['status'], $path.' must stay behind the login gate');
        }
        // The health check and the webhooks keep their own exemptions.
        self::assertSame(200, $this->request('GET', '/health', [])['status']);
    }

    /**
     * Every path variant carries forged identity headers. The request either reaches PHP (200: then no HTTP_REMOTE_*
     * parameter may be there, and the URI is the one we expect) or is stopped at the login gate (never a 200 from PHP
     * for an authenticated UI path). The strip is the single layer on these paths.
     */
    #[DataProvider('pathTricks')]
    public function testPathTricksDoNotSmuggleAnIdentityPastTheGate(string $path, bool $reachesPhp): void
    {
        $response = $this->request('GET', $path, ['Remote-User: admin', 'Remote_Groups: fleet-admins', 'REMOTE-EMAIL: a@example.com']);

        if (!$reachesPhp) {
            self::assertNotSame(200, $response['status'], $path.' must not reach PHP; the gate is closed to it');
            self::assertSame([], $response['json']['params'], $path.' must not reach PHP');

            return;
        }
        self::assertSame(200, $response['status'], $path.' is an API path and must reach the app');
        $params = $response['json']['params'];
        self::assertNotSame([], $params, $path.' must reach PHP');
        foreach (array_keys($params) as $name) {
            self::assertDoesNotMatchRegularExpression('/^HTTP_REMOTE_/i', (string) $name, $path.' leaked '.$name);
        }
        self::assertSame($path, $params['REQUEST_URI'] ?? null, $path.' reached PHP under a different URI');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function pathTricks(): iterable
    {
        yield 'plain' => ['/api/v1/tokens/self', true];
        yield 'index.php under the API' => ['/api/v1/index.php/admin', true];
        yield 'doubled slash' => ['//api/v1/tokens/self', true];
        yield 'upper case' => ['/API/V1/tokens/self', true];
        yield 'upper case short' => ['/API/V1/x', true];
        yield 'dot segments' => ['/api/v1/../admin', false];
        yield 'encoded dot segments' => ['/api/v1/%2e%2e/admin', false];
        yield 'encoded slash' => ['/api/v1%2f..%2fadmin', false];
    }

    /**
     * @param list<string>|array<string, string> $headers raw header lines
     *
     * @return array{status: int, headers: array<string, string>, json: array{params: array<string, string>, stdin?: int}}
     */
    private function request(string $method, string $path, array $headers, string $body = ''): array
    {
        $socket = fsockopen('127.0.0.1', $this->port, $errno, $error, 5.0);
        self::assertNotFalse($socket, (string) $error);
        stream_set_timeout($socket, 15);
        $raw = $method.' '.$path." HTTP/1.1\r\nHost: fleet.example.com\r\nConnection: close\r\n";
        foreach ($headers as $line) {
            $raw .= $line."\r\n";
        }
        if ('' !== $body) {
            $raw .= 'Content-Length: '.\strlen($body)."\r\n";
        }
        fwrite($socket, $raw."\r\n".$body);
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $content] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $lines = explode("\r\n", $head);
        preg_match('#^HTTP/\S+ (\d{3})#', $lines[0], $m);
        $parsed = [];
        foreach (\array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower($name)] = trim($value);
            }
        }
        if (isset($parsed['transfer-encoding']) && str_contains($parsed['transfer-encoding'], 'chunked')) {
            $decoded = '';
            while ('' !== $content) {
                [$size, $rest] = array_pad(explode("\r\n", $content, 2), 2, '');
                $length = (int) hexdec($size);
                if (0 === $length) {
                    break;
                }
                $decoded .= substr($rest, 0, $length);
                $content = substr($rest, $length + 2);
            }
            $content = $decoded;
        }
        $json = json_decode($content, true);
        /** @var array{params: array<string, string>, stdin?: int} $result */
        $result = \is_array($json) && isset($json['params']) && \is_array($json['params']) ? $json : ['params' => [], 'stdin' => 0];

        return ['status' => (int) ($m[1] ?? 0), 'headers' => $parsed, 'json' => $result];
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, (string) $error);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** A minimal FastCGI responder: answers every request with the parameters it received, as JSON. */
    private static function upstreamScript(): string
    {
        return <<<'PHP'
            <?php
            $path = $argv[1];
            @unlink($path);
            $server = stream_socket_server('unix://'.$path, $errno, $error);
            if (false === $server) { fwrite(STDERR, $error); exit(1); }
            $read = static function ($c, int $n): ?string {
                $buf = '';
                while (strlen($buf) < $n) {
                    $chunk = fread($c, $n - strlen($buf));
                    if (false === $chunk || '' === $chunk) { return null; }
                    $buf .= $chunk;
                }
                return $buf;
            };
            $record = static fn (int $type, int $id, string $body): string => pack('CCnnCC', 1, $type, $id, strlen($body), 0, 0).$body;
            while (true) {
                $c = @stream_socket_accept($server, 60);
                if (false === $c) { continue; }
                $params = []; $stdin = 0; $rawParams = '';
                while (null !== ($h = $read($c, 8))) {
                    $r = unpack('Cversion/Ctype/nid/nlen/Cpad/Cres', $h);
                    $body = $r['len'] > 0 ? $read($c, $r['len']) : '';
                    if ($r['pad'] > 0) { $read($c, $r['pad']); }
                    if (null === $body) { break; }
                    if (4 === $r['type']) { $rawParams .= $body; }
                    if (5 === $r['type']) {
                        $stdin += strlen($body);
                        if (0 === $r['len']) {
                            $o = 0; $n = strlen($rawParams);
                            $len = static function () use (&$o, $rawParams): int {
                                $b = ord($rawParams[$o]);
                                if ($b < 128) { $o += 1; return $b; }
                                $v = unpack('N', substr($rawParams, $o, 4))[1] & 0x7fffffff; $o += 4; return $v;
                            };
                            while ($o < $n) { $nl = $len(); $vl = $len(); $params[substr($rawParams, $o, $nl)] = substr($rawParams, $o + $nl, $vl); $o += $nl + $vl; }
                            $payload = json_encode(['params' => $params, 'stdin' => $stdin]);
                            fwrite($c, $record(6, $r['id'], "Status: 200 OK\r\nContent-Type: application/json\r\nCache-Control: public, max-age=3600\r\n\r\n".$payload));
                            fwrite($c, $record(6, $r['id'], ''));
                            fwrite($c, $record(3, $r['id'], pack('NCCCCCC', 0, 0, 0, 0, 0, 0, 0)));
                            break;
                        }
                    }
                }
                fclose($c);
            }
            PHP;
    }
}
