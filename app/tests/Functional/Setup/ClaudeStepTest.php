<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Tests\Support\WizardTestCase;

final class ClaudeStepTest extends WizardTestCase
{
    private const string TOKEN = 'sk-ant-oat01-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function testSkipLeavesNothingStored(): void
    {
        $crawler = $this->open('/setup/claude');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('No token is stored.', trim($crawler->filter('[data-testid=setup-claude-stored]')->text()));

        $this->submit($crawler, 'Skip for now', ['claude_token[token]' => self::TOKEN]);

        self::assertResponseRedirects('/setup/finish');
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM secret'));
    }

    public function testTokenStoredEncrypted(): void
    {
        $crawler = $this->open('/setup/claude');
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => '  '.self::TOKEN.' ']);

        self::assertResponseRedirects('/setup/finish');
        self::assertSame(self::TOKEN, $this->secretStore()->getFleetWide('claude_oauth_token'));
        $row = $this->em()->getConnection()->fetchAssociative('SELECT scope, scope_id, name, ciphertext FROM secret');
        self::assertIsArray($row);
        self::assertSame(['fleet', '', 'claude_oauth_token'], [$row['scope'], $row['scope_id'], $row['name']]);
        self::assertStringNotContainsString(self::TOKEN, (string) $row['ciphertext']);
        self::assertStringNotContainsString('sk-ant', (string) $row['ciphertext']);
    }

    public function testPageNeverEchoesTheToken(): void
    {
        $crawler = $this->open('/setup/claude');
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => self::TOKEN]);

        $crawler = $this->open('/setup/claude');
        self::assertSame('A token is stored.', trim($crawler->filter('[data-testid=setup-claude-stored]')->text()));
        self::assertStringNotContainsString(self::TOKEN, $this->body());
        self::assertSame('', (string) $crawler->filter('input[type=password]')->attr('value'));

        // Nor on the way back with an error.
        $bad = 'not a token with spaces '.self::TOKEN;
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => $bad]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('does not look like a Claude token', $this->body());
        self::assertStringNotContainsString($bad, $this->body());
        self::assertStringNotContainsString(self::TOKEN, $this->body());
        self::assertSame(self::TOKEN, $this->secretStore()->getFleetWide('claude_oauth_token'), 'the stored token is untouched');
    }

    public function testBlankWithNothingStoredAsksForTheTokenOrSkip(): void
    {
        $crawler = $this->open('/setup/claude');
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Skip for now', $this->body());
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
    }

    public function testBlankKeepsAStoredToken(): void
    {
        $crawler = $this->open('/setup/claude');
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => self::TOKEN]);

        $crawler = $this->open('/setup/claude');
        $this->submit($crawler, 'Save and continue', ['claude_token[token]' => '']);

        self::assertResponseRedirects('/setup/finish');
        self::assertSame(self::TOKEN, $this->secretStore()->getFleetWide('claude_oauth_token'));
    }

    public function testACrossOriginPostStoresNothing(): void
    {
        $this->open('/setup/claude');
        $this->post('/setup/claude', ['claude_token' => ['token' => self::TOKEN, 'save' => '', '_token' => 'csrf-token']], fetchSite: 'cross-site');

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
    }
}
