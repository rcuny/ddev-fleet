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

    public function testAnUnterminatedOscPayloadIsCutToTheEndOfItsLine(): void
    {
        $text = new TerminalText(new RegisteredSecretsMasker());

        $out = $text->clean("before \e]0;title that never ends\nnext line stays\n\e]52;c;c2VjcmV0");

        self::assertSame("before\nnext line stays", $out['text']);
    }

    public function testASecretSplitByAnEscapeOrJoinedFromAWrappedLineIsStillMasked(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('hunter2hunter2');
        $text = new TerminalText($masker);

        // capture-pane -J joins wrapped lines; an escape in the middle must not hide the secret from the masker.
        self::assertSame('a [masked] b', $text->clean("a hunter2\e[0mhunter2 b")['text']);
        self::assertSame('a [masked] b', $text->clean("a hunt\e]0;x\x07er2hunter2 b")['text']);
        self::assertStringNotContainsString('hunter2', $text->clean("pre hunter2hunter2\n")['text']);
    }
}
