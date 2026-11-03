<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\TokenRedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenRedactingProcessorTest extends TestCase
{
    private const string SECRET = 'S3cr3t-Setup-T0ken_value.abc';

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private static function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'request', Level::Info, $message, $context, $extra);
    }

    private static function redact(LogRecord $record): LogRecord
    {
        return new TokenRedactingProcessor()($record);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urls(): iterable
    {
        yield 'lower case' => ['https://fleet.example.com/setup?token='.self::SECRET];
        yield 'upper case' => ['https://fleet.example.com/setup?TOKEN='.self::SECRET];
        yield 'mixed case' => ['https://fleet.example.com/setup?Token='.self::SECRET];
        yield 'not the first parameter' => ['https://fleet.example.com/setup?a=1&token='.self::SECRET.'&b=2'];
        yield 'with a fragment' => ['https://fleet.example.com/setup?token='.self::SECRET.'#top'];
        yield 'encoded equals sign' => ['https://fleet.example.com/setup?token%3D'.self::SECRET];
        yield 'another token parameter' => ['https://fleet.example.com/x?access_token='.self::SECRET];
    }

    #[DataProvider('urls')]
    public function testASetupUrlIsRedactedInTheMessage(string $url): void
    {
        $out = self::redact(self::record('Matched route "setup" for '.$url.' (done)'));

        self::assertStringNotContainsString(self::SECRET, $out->message);
        self::assertStringContainsString('[redacted]', $out->message);
        self::assertStringContainsString('(done)', $out->message);
        self::assertStringContainsString('https://fleet.example.com/', $out->message, 'the rest of the line stays');
    }

    #[DataProvider('urls')]
    public function testASetupUrlIsRedactedInTheContextLikeRouterListenersRequestUri(string $url): void
    {
        $out = self::redact(self::record('Matched route "{route}".', [
            'route' => 'setup',
            'request_uri' => $url,
            'nested' => ['deeper' => ['uri' => $url], 'n' => 3],
        ], ['url' => $url]));

        self::assertStringNotContainsString(self::SECRET, (string) json_encode([$out->context, $out->extra]));
        self::assertSame('setup', $out->context['route']);
        self::assertSame(3, $out->context['nested']['n']);
        self::assertStringContainsString('[redacted]', $out->context['request_uri']);
    }

    public function testQueryParametersAroundTheTokenSurvive(): void
    {
        $out = self::redact(self::record('GET /setup?a=1&token='.self::SECRET.'&b=2'));

        self::assertSame('GET /setup?a=1&token=[redacted]&b=2', $out->message);
    }

    public function testAnExceptionWhoseMessageCarriesTheUrlIsDescribedWithoutIt(): void
    {
        $e = new \RuntimeException('Bad request for /setup?token='.self::SECRET, 12, new \LogicException('inner token='.self::SECRET));

        $out = self::redact(self::record('failed', ['exception' => $e, 'other' => new \RuntimeException('plain')]));

        self::assertIsArray($out->context['exception']);
        self::assertSame(\RuntimeException::class, $out->context['exception']['class']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($out->context['exception']));
        self::assertSame(12, $out->context['exception']['code']);
        self::assertInstanceOf(\RuntimeException::class, $out->context['other'], 'an exception without a token is left for the formatter');
    }

    public function testOrdinaryRecordsAreUnchanged(): void
    {
        $record = self::record('Matched route "home".', ['request_uri' => 'https://fleet.example.com/?page=2', 'count' => 5, 'flag' => true]);

        $out = self::redact($record);

        self::assertSame($record->message, $out->message);
        self::assertSame($record->context, $out->context);
        self::assertSame($record->level, $out->level);
        self::assertSame($record->channel, $out->channel);
    }

    public function testAMessageThatMentionsTokensWithoutAValueIsKept(): void
    {
        self::assertSame('The setup token has expired.', self::redact(self::record('The setup token has expired.'))->message);
        self::assertSame('token= is empty', self::redact(self::record('token= is empty'))->message);
    }
}
