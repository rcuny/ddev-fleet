<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FLE-327: turns Docker events into isolation passes within about a second. A relevant event starts a debounce window
 * (DEBOUNCE seconds, so a `ddev start` that fires a dozen events costs about one pass); when it ends, one IsolationConverger pass
 * runs. A pass that finds another running (the 120 s watcher, a CLI command) is asked again, so an event is never lost to the
 * lock. The passes our own link networks cause (connect/disconnect) end the loop by themselves: a second pass changes nothing.
 * The watcher's 120 s pass stays the backstop for a listener that is down.
 */
final class IsolationEventListener
{
    public const float DEBOUNCE = 1.0;
    public const int BUSY_RETRIES = 10;

    /** @var \Closure(int): void */
    private readonly \Closure $sleepMs;

    /** @var \Closure(): int */
    private readonly \Closure $nowNano;

    /** Docker time (ns) up to which events are covered by a pass that started after them. */
    private int $coveredNano = 0;

    /**
     * @param \Closure(): IsolationPass  $pass    one converger pass (`$converger->pass(...)`)
     * @param (\Closure(int): void)|null $sleepMs
     * @param (\Closure(): int)|null     $nowNano host time in nanoseconds (tests inject a fake)
     */
    public function __construct(
        private readonly \Closure $pass,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?\Closure $sleepMs = null,
        ?\Closure $nowNano = null,
    ) {
        $this->nowNano = $nowNano ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Consumes the source until its stream ends. A relevant event waits out the debounce, then one pass runs; events stamped
     * (`timeNano`) before that pass started are covered by it and start none, so a burst costs one pass plus at most one for
     * events that really came after.
     *
     * @param (\Closure(): bool)|null $stop asked after each window of the stream
     *
     * @return bool false when Docker did not answer (the unit restarts the listener)
     */
    public function run(IsolationEventSource $source, ?\Closure $stop = null): bool
    {
        do {
            $ok = $source->consume(function (string $chunk): void {
                foreach (explode("\n", $chunk) as $line) {
                    if ('' !== $line && self::relevant($line) && !$this->covered($line)) {
                        ($this->sleepMs)((int) (self::DEBOUNCE * 1000));
                        // Every event stamped before this instant is covered by the pass that starts now: the events that
                        // piled up during the sleep are dropped by their own timestamps, not by luck of chunking.
                        $this->coveredNano = ($this->nowNano)();
                        $this->converge();

                        return;
                    }
                }
            });
        } while ($ok && !(null !== $stop && $stop()));

        return $ok;
    }

    /**
     * Does this `docker events --format '{{json .}}'` line concern the shared network or a link network? Container events
     * only for ddev containers (the router, the web and db containers); network events only for `ddev_default` and `fleet-link-*`.
     */
    public static function relevant(string $line): bool
    {
        try {
            $event = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!\is_array($event) || !\is_string($event['Type'] ?? null) || !\is_string($event['Action'] ?? null)) {
            return false;
        }
        $attributes = \is_array($event['Actor']['Attributes'] ?? null) ? $event['Actor']['Attributes'] : [];
        $name = \is_string($attributes['name'] ?? null) ? $attributes['name'] : '';

        return match ($event['Type']) {
            'container' => \in_array($event['Action'], ['start', 'die'], true) && str_starts_with($name, 'ddev-'),
            'network' => \in_array($event['Action'], ['create', 'destroy', 'connect', 'disconnect'], true)
                && ('ddev_default' === $name || str_starts_with($name, 'fleet-link-')),
            default => false,
        };
    }

    private function covered(string $line): bool
    {
        $event = json_decode($line, true);
        $nano = \is_array($event) && \is_int($event['timeNano'] ?? null) ? $event['timeNano'] : null;

        return null !== $nano && $nano <= $this->coveredNano;
    }

    private function converge(): void
    {
        for ($try = 0; $try <= self::BUSY_RETRIES; ++$try) {
            try {
                $pass = ($this->pass)();
            } catch (\Throwable $e) {
                $this->logger->error('The isolation events listener pass failed.', ['exception' => $e]);

                return;
            }
            if (!$pass->busy) {
                if (!$pass->ok()) {
                    $this->logger->error('The isolation pass (events listener) failed: '.$pass->error);
                } elseif ($pass->applied) {
                    $this->logger->info('The isolation ruleset was refreshed after a Docker event.');
                }

                return;
            }
            ($this->sleepMs)(500);
        }
        $this->logger->warning('The isolation pass stayed busy for 5 s; the next event or the watcher retries.');
    }
}
