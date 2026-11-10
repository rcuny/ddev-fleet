<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * Pure rollback rules (rulings R8 and R14).
 *
 *  - Never across a major boundary, never below 1.1.0 (the first release that can be installed over by this tooling),
 *    never to a pre-release.
 *  - The database comes back from the backup taken at the start of the upgrade being undone: the newest history `start`
 *    line whose `from` is the rollback target, whose `to` is newer, and whose backup file still exists. Doctrine
 *    migrations only go forward, so code at the old tag over a newer schema would not boot.
 *  - Without that backup the rollback refuses with its options, unless `--no-db-restore` is given AND the database has
 *    no migration the target lacks (`schemaAhead` false).
 */
final class RollbackPlanner
{
    public const string FLOOR = '1.1.0';

    /**
     * @param list<HistoryEntry> $history         newest first, as UpgradeHistory::entries() returns it
     * @param list<string>       $existingBackups backup file names that exist now
     */
    public function plan(Version $installed, Version $target, array $history, array $existingBackups, bool $schemaAhead, bool $noDbRestore): RollbackPlan
    {
        $refusals = $this->boundaryRefusals($installed, $target);
        if ([] !== $refusals) {
            return new RollbackPlan($refusals);
        }

        $entry = $this->findStart($target, $history, $existingBackups);
        if ($noDbRestore) {
            if ($schemaAhead) {
                return new RollbackPlan(['--no-db-restore is refused: the database has migrations '.$target->tag().' does not know, and the old code would not boot over them. Drop --no-db-restore so the database is restored from the pre-upgrade backup.']);
            }

            return new RollbackPlan([], null, false, $entry?->at);
        }
        if (null === $entry) {
            return new RollbackPlan([\sprintf(
                'No pre-upgrade backup of %s was found in the upgrade history (or its file is gone), so the database cannot be put back. Options: restore a backup by hand first (`fleet backup:restore <file>`, see docs/reference/05-upgrade.md), then re-run with --no-db-restore if the schema allows it; or stay on the installed version.',
                $target->tag(),
            )]);
        }

        return new RollbackPlan([], $entry->backup, true, $entry->at);
    }

    /**
     * @return list<string>
     */
    private function boundaryRefusals(Version $installed, Version $target): array
    {
        if ($target->major !== $installed->major) {
            return [\sprintf('A rollback across a major version (%s to %s) is not supported: the series are not interchangeable.', $installed->tag(), $target->tag())];
        }
        if ($target->isPreRelease()) {
            return [\sprintf('%s is a pre-release; a rollback goes to a published release only.', $target->tag())];
        }
        if ($target->compare(Version::parse(self::FLOOR)) < 0) {
            return [\sprintf('A rollback below %s is not supported (asked for %s).', 'v'.self::FLOOR, $target->tag())];
        }

        return [];
    }

    /**
     * @param list<HistoryEntry> $history
     * @param list<string>       $existingBackups
     */
    private function findStart(Version $target, array $history, array $existingBackups): ?HistoryEntry
    {
        foreach ($history as $entry) {
            if (HistoryEntry::START !== $entry->event || null === $entry->backup || !\in_array($entry->backup, $existingBackups, true)) {
                continue;
            }
            $from = Version::tryParse($entry->from);
            $to = Version::tryParse($entry->to);
            if (null !== $from && null !== $to && 0 === $from->compare($target) && $to->isNewerThan($from)) {
                return $entry;
            }
        }

        return null;
    }
}
