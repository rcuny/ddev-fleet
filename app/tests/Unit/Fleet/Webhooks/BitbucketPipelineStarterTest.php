<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Webhooks;

use App\Fleet\Webhooks\BitbucketPipelineStarter;
use App\Fleet\Webhooks\PipelineStartFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BitbucketPipelineStarterTest extends TestCase
{
    private const string TOKEN = 'ATCTT-unit-token';

    public function testItPostsTheCustomPipelineTargetWithTheBearerHeader(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options];

            return new MockResponse('{}', ['http_code' => 201]);
        });

        new BitbucketPipelineStarter($client)->start('acme/blog', 'renovate-merge', 'develop', self::TOKEN);

        self::assertCount(1, $seen);
        self::assertSame('POST', $seen[0][0]);
        self::assertSame('https://api.bitbucket.org/2.0/repositories/acme/blog/pipelines/', $seen[0][1]);
        self::assertContains('Authorization: Bearer '.self::TOKEN, $seen[0][2]['headers']);
        self::assertStringNotContainsString(self::TOKEN, $seen[0][1].$seen[0][2]['body']);
        self::assertSame(
            ['target' => ['type' => 'pipeline_ref_target', 'ref_type' => 'branch', 'ref_name' => 'develop', 'selector' => ['type' => 'custom', 'pattern' => 'renovate-merge']]],
            json_decode($seen[0][2]['body'], true),
        );
    }

    public function testEachPathPartIsEncoded(): void
    {
        $url = '';
        $client = new MockHttpClient(static function (string $method, string $u) use (&$url): MockResponse {
            $url = $u;

            return new MockResponse('', ['http_code' => 201]);
        });

        new BitbucketPipelineStarter($client)->start('a b/c?d', 'p', 'main', self::TOKEN);

        self::assertSame('https://api.bitbucket.org/2.0/repositories/a%20b/c%3Fd/pipelines/', $url);
    }

    public function testANon2xxAnswerFailsWithTheStatusOnly(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":{"message":"Bearer '.self::TOKEN.' rejected"}}', ['http_code' => 403]));

        try {
            new BitbucketPipelineStarter($client)->start('acme/blog', 'p', 'main', self::TOKEN);
            self::fail('expected a failure');
        } catch (PipelineStartFailed $e) {
            self::assertSame('Bitbucket answered HTTP 403', $e->getMessage());
        }
    }

    public function testATransportFailureNamesOnlyTheClass(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('could not resolve api.bitbucket.org with '.self::TOKEN));

        try {
            new BitbucketPipelineStarter($client)->start('acme/blog', 'p', 'main', self::TOKEN);
            self::fail('expected a failure');
        } catch (PipelineStartFailed $e) {
            self::assertSame('Bitbucket request failed (TransportException)', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }
}
