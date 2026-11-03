<?php

declare(strict_types=1);

namespace App\Fleet\Host;

/**
 * Reads the "reboot required" flag Debian's update tooling leaves in /run (`reboot-required`, with the packages in
 * `reboot-required.pkgs`). Pure file reads, safe inside an HTTP request. The paths are constructor arguments so tests
 * (and the `fleet.reboot_flag_file` / `fleet.reboot_packages_file` parameters) can point elsewhere. A missing or
 * unreadable flag means "not required"; the fleet never reboots the host by itself.
 */
final readonly class RebootSignal
{
    public const int MAX_PACKAGES = 50;

    private const int MAX_READ_BYTES = 16384;
    private const string PACKAGE_NAME = '/^[A-Za-z0-9][A-Za-z0-9+.:~_-]{0,99}$/D';

    public function __construct(
        private string $flagFile = '/run/reboot-required',
        private string $packagesFile = '/run/reboot-required.pkgs',
    ) {
    }

    public function status(): RebootStatus
    {
        if (!is_file($this->flagFile)) {
            return new RebootStatus(false);
        }

        $packages = [];
        $truncated = false;
        $text = @file_get_contents($this->packagesFile, false, null, 0, self::MAX_READ_BYTES);
        if (\is_string($text)) {
            $lines = preg_split('/\R/', $text) ?: [];
            if (\strlen($text) >= self::MAX_READ_BYTES) {
                // Cut mid-file: the last line may be half a name.
                array_pop($lines);
                $truncated = true;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                // Lines that are not a plausible package name are dropped, not repaired.
                if (1 !== preg_match(self::PACKAGE_NAME, $line) || \in_array($line, $packages, true)) {
                    continue;
                }
                if (\count($packages) >= self::MAX_PACKAGES) {
                    $truncated = true;
                    break;
                }
                $packages[] = $line;
            }
        }

        return new RebootStatus(true, $packages, $truncated);
    }
}
