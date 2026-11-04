<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** One verification step: `critical` ones fail the upgrade (exit 5), the others only warn. */
final readonly class VerifyResult
{
    public function __construct(public string $name, public bool $ok, public string $detail, public bool $critical = true)
    {
    }

    /**
     * @return array{name: string, ok: bool, detail: string, critical: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'ok' => $this->ok, 'detail' => $this->detail, 'critical' => $this->critical];
    }
}
