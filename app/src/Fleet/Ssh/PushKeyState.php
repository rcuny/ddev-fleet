<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * The outcome of asking a git host whether the host's push key is registered there (FLE-271).
 */
enum PushKeyState: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case NoPushKey = 'no_push_key';
    case HostNotTrusted = 'host_not_trusted';
    case Unreachable = 'unreachable';

    public function message(string $host): string
    {
        return match ($this) {
            self::Registered => \sprintf('The push key is registered on %s.', $host),
            self::NotRegistered => \sprintf('The push key is not registered on the git host %s: add its public key there (the SSH keys view shows it).', $host),
            self::NoPushKey => 'This host has no push key yet.',
            self::HostNotTrusted => \sprintf('The host key of %s is not in the fleet known hosts: run "fleet:ssh:trust %s" after comparing the fingerprints.', $host, $host),
            self::Unreachable => \sprintf('%s could not be reached over SSH, so the push key could not be tested.', $host),
        };
    }
}
