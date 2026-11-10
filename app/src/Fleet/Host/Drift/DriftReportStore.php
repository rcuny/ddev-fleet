<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

use App\Fleet\Ssh\AtomicFile;

/** The host's last drift report, one JSON file under FLEET_HOME (FLE-261). Already masked when it is written. */
final readonly class DriftReportStore
{
    public function __construct(private string $file)
    {
    }

    public function save(DriftReport $report): void
    {
        AtomicFile::write($this->file, json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n", 0o640);
    }

    public function last(): ?DriftReport
    {
        $text = @file_get_contents($this->file, false, null, 0, 4_000_000);
        if (!\is_string($text)) {
            return null;
        }
        try {
            $data = json_decode($text, true, 16, \JSON_THROW_ON_ERROR);

            return \is_array($data) ? DriftReport::fromArray($data) : null;
        } catch (\JsonException|DriftReportInvalid) {
            return null;
        }
    }
}
