<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\MailpitHealthcheckFile;
use PHPUnit\Framework\TestCase;

final class MailpitHealthcheckFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fle313-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.ddev', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testWritesTheFragmentAndIsIdempotent(): void
    {
        $lines = [];
        $warn = static function (string $l) use (&$lines): void { $lines[] = $l; };
        self::assertSame(MailpitHealthcheckFile::RELATIVE_PATH, MailpitHealthcheckFile::write($this->dir, $warn));
        $path = $this->dir.'/'.MailpitHealthcheckFile::RELATIVE_PATH;
        self::assertFileExists($path);
        self::assertStringStartsWith(MailpitHealthcheckFile::MARKER, (string) file_get_contents($path));
        self::assertStringContainsString('/livez', (string) file_get_contents($path));
        self::assertStringNotContainsString('MP_UI_AUTH', (string) file_get_contents($path), 'no credential in the image');
        $mtime = filemtime($path);
        self::assertSame(MailpitHealthcheckFile::RELATIVE_PATH, MailpitHealthcheckFile::write($this->dir, $warn));
        self::assertSame($mtime, filemtime($path));
        self::assertSame([], $lines);
    }

    public function testNoDdevDirectoryWritesNothing(): void
    {
        self::assertNull(MailpitHealthcheckFile::write($this->dir.'/missing', static function (): void {}));
    }

    public function testSymlinkedWebBuildIsRefusedWithAWarning(): void
    {
        mkdir($this->dir.'/elsewhere');
        symlink($this->dir.'/elsewhere', $this->dir.'/.ddev/web-build');
        $lines = [];
        self::assertNull(MailpitHealthcheckFile::write($this->dir, static function (string $l) use (&$lines): void { $lines[] = $l; }));
        self::assertCount(1, $lines);
        self::assertFileDoesNotExist($this->dir.'/elsewhere/Dockerfile.fleet-mailpit-healthcheck');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scripts(): iterable
    {
        yield 'bare host' => ["curl --fail 127.0.0.1:8025\n", "curl --fail 127.0.0.1:8025/livez\n"];
        yield 'trailing slash' => ["curl --fail http://127.0.0.1:8025/ || exit 1\n", "curl --fail http://127.0.0.1:8025/livez || exit 1\n"];
        yield 'already livez' => ["curl -f 127.0.0.1:8025/livez\n", "curl -f 127.0.0.1:8025/livez\n"];
        yield 'quoted, other lines kept' => ["a\ncurl -s \"127.0.0.1:8025\" >/dev/null\nb\n", "a\ncurl -s \"127.0.0.1:8025/livez\" >/dev/null\nb\n"];
        yield 'probe absent' => ["curl --fail 127.0.0.1:80\n", "curl --fail 127.0.0.1:80\n"];
    }

    /**
     * Runs the real RUN body against a stand-in script: the rewrite works and a missing probe never fails the step.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scripts')]
    public function testTheBuildStepRewritesTheProbe(string $before, string $after): void
    {
        if (1 !== preg_match('/^RUN (.+)$/ms', MailpitHealthcheckFile::render(), $m)) {
            self::fail('no RUN step');
        }
        $target = $this->dir.'/healthcheck.sh';
        file_put_contents($target, $before);
        $body = str_replace('/healthcheck.sh', $target, str_replace(" \\\n", ' ', $m[1]));
        exec('sh -c '.escapeshellarg($body).' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame($after, file_get_contents($target));
        if ('probe absent' === array_search([$before, $after], iterator_to_array(self::scripts()), true)) {
            self::assertStringContainsString('WARNING', implode("\n", $out));
        }
    }

    public function testMissingHealthcheckScriptDoesNotFailTheBuild(): void
    {
        $body = str_replace('/healthcheck.sh', $this->dir.'/nope.sh', str_replace(" \\\n", ' ', substr(MailpitHealthcheckFile::render(), strpos(MailpitHealthcheckFile::render(), 'RUN ') + 4)));
        exec('sh -c '.escapeshellarg($body).' 2>&1', $out, $code);
        self::assertSame(0, $code);
        self::assertStringContainsString('WARNING', implode("\n", $out));
    }
}
