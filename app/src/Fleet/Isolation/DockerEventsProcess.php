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

    /** @var list<string> */
    private const array FILTERS = [
        '--filter', 'type=container', '--filter', 'type=network',
        '--filter', 'event=start', '--filter', 'event=die',
        '--filter', 'event=create', '--filter', 'event=destroy',
        '--filter', 'event=connect', '--filter', 'event=disconnect',
    ];

    private ?int $since = null;

    public function __construct(private readonly CommandRunner $runner)
    {
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
        $since = $this->since ??= time();
        $until = max($since, time()) + self::WINDOW;
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
        $this->since = $until;

        return true;
    }
}
