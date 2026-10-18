<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Fleet\FleetPaths;
use App\Setup\SetupCookie;
use App\Setup\SetupState;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetupStateTest extends KernelTestCase
{
    use FleetDatabase;

    private SetupFixture $fx;
    private SetupCookie $cookie;
    private SetupState $state;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
        $this->cookie = new SetupCookie('a-long-enough-app-secret-for-tests', $this->fx->clock);
        $this->state = new SetupState($this->fx->settings, $this->cookie);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testNothingIsOpenOrCompleteBeforeInit(): void
    {
        self::assertFalse($this->state->isOpen());
        self::assertFalse($this->state->isComplete());
        self::assertFalse($this->state->permits('1.'.str_repeat('a', 64)));
    }

    public function testOpenUntilFinished(): void
    {
        $this->fx->readyHost();

        self::assertTrue($this->state->isOpen());
        self::assertFalse($this->state->isComplete());
    }

    public function testPermitsOnlyACookieFromTheCurrentToken(): void
    {
        $host = $this->fx->readyHost();
        $this->fx->tokens->mint();
        $value = $this->cookie->issue($host)->getValue();

        self::assertTrue($this->state->permits($value));
        self::assertFalse($this->state->permits(null));
        self::assertFalse($this->state->permits('garbage'));

        $this->fx->tokens->mint();
        self::assertFalse($this->state->permits($value), 'a re-minted token kills older sessions');
    }

    public function testAfterFinishNothingIsPermittedAndTheCookieStaysDead(): void
    {
        $host = $this->fx->readyHost();
        $this->fx->tokens->mint();
        $value = $this->cookie->issue($host)->getValue();

        $this->fx->finisher->finish('alice');

        self::assertTrue($this->state->isComplete());
        self::assertFalse($this->state->isOpen());
        self::assertFalse($this->state->permits($value));
    }

    public function testResetReopensButNotForOldCookies(): void
    {
        $host = $this->fx->readyHost();
        $this->fx->tokens->mint();
        $old = $this->cookie->issue($host)->getValue();
        $this->fx->finisher->finish('alice');

        $host->reopenSetup();
        $this->fx->settings->save($host);
        $this->fx->tokens->mint();

        self::assertTrue($this->state->isOpen());
        self::assertFalse($this->state->permits($old));
        self::assertTrue($this->state->permits($this->cookie->issue($host)->getValue()));
    }
}
