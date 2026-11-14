<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;

/**
 * FLE-327: `docker events` as an IsolationEventSource, through the CommandRunner seam. Container start/die and network
 * create/destroy/connect/disconnect; which containers and networks count is IsolationEventListener::relevant().
 *
 * The runner keeps the output of a command in memory, so the stream is read in windows of WINDOW seconds
 * (`--since` the end of the last window, `--until` its end): memory stays bounded and no event falls between two windows.
 */
final class DockerEventsProcess implements IsolationEventSource
{
    public const int WINDOW = 3600;

    /** A window that ends more than this many seconds before its `--until` ended abnormally. */
    public const int EARLY_SECONDS = 30;

    /** @var list<string> */
    private const array FILTERS = [
        '--filter', 'type=container', '--filter', 'type=network',
        '--filter', 'event=start', '--filter', 'event=die',
        '--filter', 'event=create', '--filter', 'event=destroy',
        '--filter', 'event=connect', '--filter', 'event=disconnect',
    ];

    private int $since;

    /**
     * @param int|null $since where the first window starts (default: now); the command sets it before its start-up pass, so
     *                        events during that pass are replayed
     */
    public function __construct(private readonly CommandRunner $runner, ?int $since = null, private readonly ?\Closure $clock = null)
    {
        $this->since = $since ?? $this->time();
    }

    private function time(): int
    {
        return null === $this->clock ? time() : ($this->clock)();
    }

    /**
     * @return list<string>
     */
    public static function argv(int $since, int $until): array
    {
        return ['docker', 'events', '--format', '{{json .}}', ...self::FILTERS, '--since', (string) $since, '--until', (string) $until];
    }

    public function consume(\Closure $onLines): bool
    {
        $since = $this->since;
        $until = max($since, $this->time()) + self::WINDOW;
        $buffer = '';
        try {
            $result = $this->runner->run(self::argv($since, $until), null, [], static function (string $stream, string $chunk) use ($onLines, &$buffer): void {
                if ('stdout' !== $stream) {
                    return;
                }
                // A chunk can end in the middle of a line: only whole lines go on.
                $buffer .= $chunk;
                $end = strrpos($buffer, "\n");
                if (false === $end) {
                    return;
                }
                $complete = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                $onLines($complete);
            });
        } catch (CommandRunnerException) {
            return false;
        }
        if (!$result->isSuccess()) {
            return false;
        }
        // Not `until`: a stream that ends early (a clean dockerd stop) must not skip the gap. And one that ends long before its
        // `until` is not a normal end: report it, so the unit restarts after its RestartSec instead of this looping hot.
        $now = $this->time();
        $this->since = min($until, $now);
        if ($now < $until - self::EARLY_SECONDS) {
            return false;
        }

        return true;
    }
}
