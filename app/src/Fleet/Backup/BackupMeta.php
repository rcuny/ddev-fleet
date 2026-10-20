<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * What a fleet backup says about itself, in a small table of the backup file (`fleet_backup_meta`), so it travels with
 * the file: today the fingerprint of the secret key that sealed its secrets. Written into the copy, never into the live
 * database; a restored database keeps the table until the next backup rewrites it.
 */
final class BackupMeta
{
    private const string TABLE = 'fleet_backup_meta';
    private const string KEY_FINGERPRINT = 'secret_key_fingerprint';

    private function __construct()
    {
    }

    /**
     * @param string|null $fingerprint null records that this host had no key (the row is removed)
     */
    public static function writeKeyFingerprint(\PDO $pdo, ?string $fingerprint): void
    {
        $pdo->exec(\sprintf('CREATE TABLE IF NOT EXISTS %s (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)', self::TABLE));
        $pdo->prepare(\sprintf('DELETE FROM %s WHERE name = ?', self::TABLE))->execute([self::KEY_FINGERPRINT]);
        if (null !== $fingerprint) {
            $pdo->prepare(\sprintf('INSERT INTO %s (name, value) VALUES (?, ?)', self::TABLE))->execute([self::KEY_FINGERPRINT, $fingerprint]);
        }
    }

    /**
     * @return string|null null when the file records none (an older backup, or one made without a key)
     */
    public static function readKeyFingerprint(\PDO $pdo): ?string
    {
        $table = $pdo->query(\sprintf("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '%s'", self::TABLE));
        if (false === $table || false === $table->fetchColumn()) {
            return null;
        }
        $statement = $pdo->prepare(\sprintf('SELECT value FROM %s WHERE name = ?', self::TABLE));
        $statement->execute([self::KEY_FINGERPRINT]);
        $value = $statement->fetchColumn();

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
