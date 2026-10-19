<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Fleet\Shell\CommandResult;
use App\Fleet\System\OutputTail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutputTailTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> text, the value that must not survive
     */
    public static function leaks(): iterable
    {
        yield 'upper-case assignment' => ['OVH_APPLICATION_SECRET=hunter2hunter2', 'hunter2hunter2'];
        yield 'lower-case assignment' => ['ovh_application_secret=hunter2hunter2', 'hunter2hunter2'];
        yield 'mixed case' => ['Ovh_Consumer_Key=hunter2hunter2', 'hunter2hunter2'];
        yield 'proxy secret in lower case' => ['fleet_proxy_secret=hunter2hunter2', 'hunter2hunter2'];
        yield 'generic token' => ['api_token=hunter2hunter2', 'hunter2hunter2'];
        yield 'colon form' => ['application_secret: hunter2hunter2', 'hunter2hunter2'];
        yield 'colon form without space' => ['password:hunter2hunter2', 'hunter2hunter2'];
        yield 'json form' => ['{"application_key": "hunter2hunter2"}', 'hunter2hunter2'];
        yield 'quoted with a space' => ['OVH_APPLICATION_KEY="hunter2 hunter2"', 'hunter2 hunter2'];
        yield 'single-quoted' => ["consumer_key='hunter2hunter2'", 'hunter2hunter2'];
        yield 'after other text' => ["error while reading\nTOKEN=hunter2hunter2 (retrying)", 'hunter2hunter2'];
    }

    #[DataProvider('leaks')]
    public function testCredentialAssignmentsAreBlankedWhateverTheirCaseOrQuoting(string $text, string $secret): void
    {
        $out = OutputTail::text($text);

        self::assertStringNotContainsString($secret, $out);
        self::assertStringContainsString('[redacted]', $out);
    }

    public function testTheNameStaysSoThePersonKnowsWhichValueWasHidden(): void
    {
        self::assertSame('OVH_APPLICATION_SECRET=[redacted]', OutputTail::text('OVH_APPLICATION_SECRET=abc'));
        self::assertSame('application_secret: [redacted]', OutputTail::text('application_secret: abc'));
    }

    public function testOrdinaryTextIsKept(): void
    {
        self::assertSame('caddy: adapting config: unrecognized directive: foo', OutputTail::text('caddy: adapting config: unrecognized directive: foo'));
        self::assertSame('(no output)', OutputTail::text("  \n"));
    }

    public function testControlCharactersAreReplacedAndTheTextIsBounded(): void
    {
        self::assertSame('a?b', OutputTail::text("a\x00b"));
        $long = OutputTail::text(str_repeat('x', 2000));
        self::assertSame(803, \strlen($long));
        self::assertStringStartsWith('...', $long);
    }

    public function testErrorOutputWinsOverStandardOutput(): void
    {
        self::assertSame('bad', OutputTail::of(new CommandResult(1, 'good', 'bad')));
        self::assertSame('good', OutputTail::of(new CommandResult(1, 'good', ' ')));
    }
}
