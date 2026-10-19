<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\TokenRenderer;
use PHPUnit\Framework\TestCase;

final class TokenRendererTest extends TestCase
{
    public function testSubstitutesInsideAWord(): void
    {
        self::assertSame('user-main@acme', TokenRenderer::render('user-[[instance]]@[[project]]', ['instance' => 'main', 'project' => 'acme']));
    }

    public function testAValueIsNeverScannedAgain(): void
    {
        self::assertSame('[[project]]', TokenRenderer::render('[[branch]]', ['branch' => '[[project]]', 'project' => 'acme']));
    }

    public function testUnresolvedTokensAreNamedOnce(): void
    {
        try {
            TokenRenderer::render('[[b]] [[a]] [[b]]', []);
            self::fail('expected a failure');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('unresolved token(s): [[a]], [[b]]', $e->getMessage());
        }
    }

    public function testTextWithoutTokensIsUntouched(): void
    {
        self::assertSame('$HOME [x] [[Upper]]', TokenRenderer::render('$HOME [x] [[Upper]]', []));
    }
}
