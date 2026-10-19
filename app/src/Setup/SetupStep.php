<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The wizard's pages, in the order the stepper shows them.
 */
enum SetupStep: string
{
    case Admin = 'admin';
    case Tls = 'tls';
    case Ssh = 'ssh';
    case Claude = 'claude';
    case Finish = 'finish';

    public function route(): string
    {
        return 'setup_'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Tls => 'Domain and TLS',
            self::Ssh => 'SSH keys',
            self::Claude => 'Claude token',
            self::Finish => 'Finish',
        };
    }

    /** Shorter label for the stepper on a phone. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Tls => 'TLS',
            self::Ssh => 'SSH',
            self::Claude => 'Claude',
            self::Finish => 'Finish',
        };
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    public function next(): ?self
    {
        return self::cases()[$this->position()] ?? null;
    }
}
