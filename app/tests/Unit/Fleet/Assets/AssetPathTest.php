<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Assets;

use App\Fleet\Assets\AssetPath;
use App\Fleet\Projects\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text rules for a path inside a project's assets directory (AssetStore adds the filesystem checks, symlinks
 * included, in AssetStoreTest).
 */
final class AssetPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function accepted(): iterable
    {
        yield 'the project directory itself' => ['', []];
        yield 'a file' => ['db.sql.gz', ['db.sql.gz']];
        yield 'nested' => ['dumps/2026/db.sql.gz', ['dumps', '2026', 'db.sql.gz']];
        yield 'a dotfile' => ['.env', ['.env']];
        yield 'spaces and brackets' => ['My Dump (1).sql', ['My Dump (1).sql']];
        yield 'punctuation' => ['a+b@c,d=e~f_g-h', ['a+b@c,d=e~f_g-h']];
        yield 'accents' => ['données/été.sql', ['données', 'été.sql']];
        yield 'cyrillic' => ['файл.sql', ['файл.sql']];
        yield 'han' => ['日本語.txt', ['日本語.txt']];
        yield 'decomposed accent (macOS names)' => ["e\u{0301}te\u{0301}.sql", ["e\u{0301}te\u{0301}.sql"]];
        yield 'digits first' => ['2026-10-19.sql', ['2026-10-19.sql']];
        yield 'two dots inside' => ['a..b', ['a..b']];
        yield 'a name of exactly 255 bytes' => [str_repeat('a', 255), [str_repeat('a', 255)]];
        yield 'eight folders deep' => [implode('/', array_fill(0, 8, 'd')), array_fill(0, 8, 'd')];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('accepted')]
    public function testAcceptsSafePaths(string $path, array $segments): void
    {
        self::assertSame($segments, AssetPath::segments($path));
        self::assertSame($path, AssetPath::join($segments));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'parent' => ['..'];
        yield 'parent first' => ['../x'];
        yield 'parent in the middle' => ['a/../b'];
        yield 'parent last' => ['a/..'];
        yield 'current directory' => ['a/./b'];
        yield 'a single dot' => ['.'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'absolute, the root' => ['/'];
        yield 'empty part' => ['a//b'];
        yield 'trailing slash' => ['a/'];
        yield 'NUL' => ["a\0b"];
        yield 'NUL hiding an extension' => ["a.sql\0.txt"];
        yield 'backslash' => ['a\\b'];
        yield 'windows traversal' => ['..\\x'];
        yield 'newline' => ["a\nb"];
        yield 'trailing newline' => ["a\n"];
        yield 'tab' => ["a\tb"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'leading space' => [' a'];
        yield 'trailing space' => ['a '];
        yield 'right-to-left override' => ["evil\u{202E}gnp.sql"];
        yield 'zero-width space' => ["zero\u{200B}width"];
        yield 'line separator' => ["x\u{2028}y"];
        yield 'no-break space' => ["a\u{00A0}b"];
        yield 'full-width slash' => ["a\u{FF0F}b"];
        yield 'invalid UTF-8' => ["a\xC3\x28b"];
        yield 'lone continuation byte' => ["\x80"];
        yield 'percent-encoded parent' => ['%2e%2e/x'];
        yield 'colon' => ['a:b'];
        yield 'star' => ['*'];
        yield 'question mark' => ['a?'];
        yield 'angle brackets' => ['<script>'];
        yield 'semicolon' => ['a;rm -rf'];
        yield 'pipe' => ['a|b'];
        yield 'quote' => ['a"b'];
        yield 'dollar' => ['$HOME'];
        yield 'backtick' => ['`id`'];
        yield 'a name of 256 bytes' => [str_repeat('a', 256)];
        yield 'a multi-byte name over 255 bytes' => [str_repeat('é', 128)];
        yield 'nine folders deep' => [implode('/', array_fill(0, 9, 'd'))];
        yield 'a path over 1024 bytes' => [implode('/', array_fill(0, 8, str_repeat('a', 200)))];
        yield 'the upload temp prefix' => ['.fleet-upload-abc.tmp'];
        yield 'the upload temp prefix in a folder' => ['x/.fleet-upload-abc.tmp'];
    }

    #[DataProvider('refused')]
    public function testRefusesUnsafePaths(string $path): void
    {
        try {
            AssetPath::segments($path);
            self::fail('Accepted '.json_encode($path, \JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (ValidationFailed $failed) {
            self::assertSame(['path'], array_keys($failed->errors));
            self::assertNotSame('', $failed->errors['path']);
        }
    }

    public function testMessagesDoNotEchoTheValue(): void
    {
        foreach (['../../secret-name', "x\0secret-name", 'secret-name:', '/secret-name'] as $path) {
            try {
                AssetPath::segments($path);
                self::fail('Accepted '.$path);
            } catch (ValidationFailed $failed) {
                self::assertStringNotContainsString('secret-name', implode(' ', $failed->errors));
            }
        }
    }

    public function testANameIsOneSegment(): void
    {
        self::assertSame('db.sql', AssetPath::name('db.sql'));
        foreach (['a/b', '', '..', '.', "a\0", '/x', '.fleet-upload-1.tmp'] as $name) {
            try {
                AssetPath::name($name);
                self::fail('Accepted '.json_encode($name));
            } catch (ValidationFailed $failed) {
                self::assertSame(['name'], array_keys($failed->errors));
            }
        }
    }

    public function testIsUsableNameSaysWhatTheBrowserMayAddress(): void
    {
        self::assertTrue(AssetPath::isUsableName('db.sql.gz'));
        self::assertTrue(AssetPath::isUsableName('.env'));
        self::assertFalse(AssetPath::isUsableName('weird:name'));
        self::assertFalse(AssetPath::isUsableName("bad\xFFbytes"));
        self::assertFalse(AssetPath::isUsableName('..'));
    }
}
