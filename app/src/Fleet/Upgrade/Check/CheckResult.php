<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/**
 * One finding of a pre-flight check. `overridable` says whether `--force=<id>` may waive it: it only matters for a
 * Block (a Warn never stops anything), and a check that must never be waived (a modified checkout, a full disk) sets it
 * to false.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $message,
        public string $remedy = '',
        public bool $overridable = false,
    ) {
    }

    public static function block(string $id, string $message, string $remedy, bool $overridable = false): self
    {
        return new self($id, Severity::Block, $message, $remedy, $overridable);
    }

    public static function warn(string $id, string $message, string $remedy = '', bool $overridable = true): self
    {
        return new self($id, Severity::Warn, $message, $remedy, $overridable);
    }

    public static function info(string $id, string $message): self
    {
        return new self($id, Severity::Info, $message);
    }

    /**
     * @return array{id: string, severity: string, message: string, remedy: string, overridable: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'severity' => $this->severity->value, 'message' => $this->message, 'remedy' => $this->remedy, 'overridable' => $this->overridable];
    }
}
