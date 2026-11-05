<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * Installs staged config through the root helper: `sudo -n -- /usr/local/libexec/fleet/apply <target>`. The only
 * argument is the target's name from a closed enum; the app never passes a path, so the helper reads exactly the
 * files FleetPaths::stagingFile() defines. `-n` makes a missing sudoers line an immediate failure, not a prompt.
 */
final readonly class PrivilegedApply implements Applier, TargetSupport
{
    public const string HELPER = '/usr/local/libexec/fleet/apply';

    /** Seconds. The helper validates, installs and reloads; a caller that applies twice must stay under php-fpm's limit. */
    public const float TIMEOUT = 60.0;

    public function __construct(private CommandRunner $runner, private string $helperPath = self::HELPER)
    {
    }

    /**
     * Whether the installed helper lists the target in its `ALLOWED_TARGETS` word list (the helper is root-owned and
     * world-readable). An unreadable or missing helper supports nothing. The sudoers line is installed with the helper.
     */
    public function supports(ApplyTarget $target): bool
    {
        $text = @file_get_contents($this->helperPath, false, null, 0, 65536);
        if (!\is_string($text) || 1 !== preg_match("/^ALLOWED_TARGETS='([a-z ]{1,100})'\$/m", $text, $m)) {
            return false;
        }

        return \in_array($target->value, explode(' ', $m[1]), true);
    }

    public function apply(ApplyTarget $target): void
    {
        try {
            $result = $this->runner->run(['sudo', '-n', '--', self::HELPER, $target->value], timeout: self::TIMEOUT);
        } catch (CommandRunnerException $e) {
            // The message shown to the user names only the kind of failure; the cause stays attached for the log.
            throw new PrivilegedApplyFailed(\sprintf('Applying "%s" did not finish (%s).', $target->value, $e instanceof CommandTimedOut ? 'timed out' : 'could not start sudo'), 0, $e);
        }

        if (!$result->isSuccess()) {
            throw new PrivilegedApplyFailed(\sprintf('Applying "%s" failed (exit %d): %s', $target->value, $result->exitCode, OutputTail::of($result)));
        }
    }
}
