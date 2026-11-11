<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Terminal\TerminalText;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use PHPUnit\Framework\TestCase;

final class TerminalTextTest extends TestCase
{
    public function testStripsEscapesControlCharactersAndTrailingBlankLines(): void
    {
        $text = new TerminalText(new RegisteredSecretsMasker());

        $out = $text->clean("\e[1;31mred\e[0m\tok \r\n\e]8;;http://x\e\\link\e]8;;\e\\\x01\x7f\n\n  \n");

        self::assertSame("red\tok\nlink", $out['text']);
        self::assertFalse($out['truncated']);
    }

    public function testMasksRegisteredSecrets(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('hunter2hunter2');

        self::assertSame('token=[masked]', new TerminalText($masker)->clean('token=hunter2hunter2')['text']);
    }

    public function testCapKeepsTheEndAndValidUtf8(): void
    {
        $out = new TerminalText(new RegisteredSecretsMasker())->clean(str_repeat('é', 40000).'fin');

        self::assertTrue($out['truncated']);
        self::assertLessThanOrEqual(TerminalText::MAX_BYTES, \strlen($out['text']));
        self::assertTrue(mb_check_encoding($out['text'], 'UTF-8'));
        self::assertStringEndsWith('fin', $out['text']);
    }
}
