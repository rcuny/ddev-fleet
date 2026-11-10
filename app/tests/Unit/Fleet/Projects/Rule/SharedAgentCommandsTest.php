<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\SharedAgentCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedAgentCommandsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lines(): iterable
    {
        yield 'ddev auth ssh' => ['ddev auth ssh -f /srv/fleet/ssh/push_ed25519', true];
        yield 'inside exec' => ["ddev exec 'ssh-add -D'", true];
        yield 'ssh-add -D alone' => ['ssh-add -D', true];
        yield 'ssh-add -t -D' => ['ssh-add -t 60 -D', true];
        yield 'extra spaces' => ['ddev   auth   ssh', true];
        yield 'ssh-add a key' => ['ssh-add /tmp/key', false];
        yield 'ssh-add -d one key' => ['ssh-add -d /tmp/key', false];
        yield 'plain command' => ['composer install', false];
        yield 'word merely contains it' => ['echo ddev-auth-ssh-notes', false];
    }

    #[DataProvider('lines')]
    public function testDetectsTheCommandsThatUseTheSharedAgent(string $line, bool $warns): void
    {
        self::assertSame($warns ? [2] : [], SharedAgentCommands::lineNumbers(['composer install', $line]));
    }

    public function testOneWarningPerOffendingList(): void
    {
        $warnings = SharedAgentCommands::warnings(['post-deploy' => ['ddev auth ssh'], 'tty1' => ['npm run watch'], 'tty2' => ['x', 'ssh-add -D']]);

        self::assertCount(2, $warnings);
        self::assertStringStartsWith('post-deploy line 1', $warnings[0]);
        self::assertStringStartsWith('tty2 line 2', $warnings[1]);
        self::assertStringContainsString('shared ssh-agent', $warnings[0]);
        self::assertSame([], SharedAgentCommands::warnings(['post-deploy' => ['composer install']]));
    }
}
