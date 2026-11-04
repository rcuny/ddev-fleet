<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * installed + catalog (+ an optional --to) -> Plan. No I/O.
 */
final class UpgradePlanner
{
    /**
     * @param string       $installedRaw the installed version text (InstalledVersion::raw())
     * @param Version|null $to           an explicit target (--to); null means the latest stable release
     *
     * @throws UnknownRelease when $to is not in the catalog
     */
    public function plan(string $installedRaw, CatalogState $catalog, ?Version $to = null): Plan
    {
        $from = Version::tryParse($installedRaw);
        if (null !== $to) {
            $target = $catalog->find($to) ?? throw new UnknownRelease($to, $catalog->stable());
        } else {
            $target = $catalog->latest();
        }
        if (null === $target) {
            return new Plan($installedRaw, $from, null, Direction::Unknown, []);
        }
        if (null === $from) {
            return new Plan($installedRaw, null, $target, Direction::Unknown, [$target]);
        }

        $order = $target->version->compare($from);
        if (0 === $order) {
            return new Plan($installedRaw, $from, $target, Direction::Same, []);
        }
        $passed = array_values(array_filter($catalog->releases, static function (Release $r) use ($from, $target, $order): bool {
            return $order > 0
                ? $r->version->isNewerThan($from) && $r->version->compare($target->version) <= 0
                : $from->compare($r->version) >= 0 && $r->version->isNewerThan($target->version);
        }));

        return new Plan($installedRaw, $from, $target, $order > 0 ? Direction::Up : Direction::Down, $passed);
    }
}
