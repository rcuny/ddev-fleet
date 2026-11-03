<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use PHPUnit\Framework\TestCase;

final class UtcDateTimeImmutableTypeTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZone);
    }

    public function testItWritesUtcWhateverZoneTheObjectCarries(): void
    {
        $value = new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2026-10-21 10:00:00', new UtcDateTimeImmutableType()->convertToDatabaseValue($value, new SQLitePlatform()));
    }

    public function testItReadsTheStoredFormatAsUtcNotInTheDefaultZone(): void
    {
        $read = new UtcDateTimeImmutableType()->convertToPHPValue('2026-10-21 10:00:00', new SQLitePlatform());

        self::assertSame('UTC', $read->getTimezone()->getName());
        self::assertSame('2026-10-21T10:00:00+00:00', $read->format(\DATE_ATOM));
    }

    public function testAnotherFormatFallsBackToUtcNotTheDefaultZone(): void
    {
        $read = new UtcDateTimeImmutableType()->convertToPHPValue('2026-10-21T10:00:00', new SQLitePlatform());

        self::assertSame('2026-10-21T10:00:00+00:00', $read->format(\DATE_ATOM));
    }

    public function testAnExplicitOffsetInTheFallbackIsHonoured(): void
    {
        $read = new UtcDateTimeImmutableType()->convertToPHPValue('2026-10-21T12:00:00+02:00', new SQLitePlatform());

        self::assertSame(1792576800, $read->getTimestamp());
    }

    public function testNullAndGarbage(): void
    {
        $type = new UtcDateTimeImmutableType();

        $nothing = json_decode('null');
        self::assertNull($type->convertToPHPValue($nothing, new SQLitePlatform())); // @phpstan-ignore staticMethod.alreadyNarrowedType

        $this->expectException(InvalidFormat::class);
        $type->convertToPHPValue('not a date', new SQLitePlatform());
    }
}
