<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * DateTimeImmutable stored as a UTC wall-clock string.
 *
 * SQLite has no timezone-aware column, and the stock type writes the wall clock of whatever zone the object
 * carries and reads it back in PHP's default zone. That silently shifts every value the day the default
 * zone differs between writer and reader. This type always writes UTC and always reads UTC, so values
 * compare correctly in SQL and survive a change of php.ini.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'utc_datetime_immutable';

    private static ?\DateTimeZone $utc = null;

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (\is_string($value) && '' !== $value) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, self::utc());
            if (false !== $parsed) {
                return $parsed;
            }
        }

        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        // Not the stored format: parse it in UTC too, never in PHP's default zone (the stock fallback does).
        try {
            return new \DateTimeImmutable(\is_string($value) ? $value : '', self::utc());
        } catch (\Exception $e) {
            throw InvalidFormat::new(\is_string($value) ? $value : get_debug_type($value), static::class, $platform->getDateTimeFormatString(), $e);
        }
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
