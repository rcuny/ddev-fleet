<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * One line of the upgrade history file. Written by the root helper (install/libexec/upgrade), read here. The fields are
 * versions, shas, timestamps, the unix user and a backup file name: never a secret.
 */
final readonly class HistoryEntry
{
    public const string START = 'start';
    public const string INSTALLER = 'installer';
    public const string VERIFY = 'verify';

    /**
     * @param string  $event  start | installer | verify
     * @param string  $result ok | failed | unverified (start lines carry "started")
     * @param ?string $backup the pre-upgrade backup file name, on the start line
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public string $event,
        public string $result,
        public string $from,
        public string $to,
        public ?string $sha,
        public string $actor,
        public ?string $backup = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $at = \is_string($data['ts'] ?? null) ? date_create_immutable($data['ts']) : false;
        foreach (['event', 'result', 'from', 'to', 'actor'] as $key) {
            if (!\is_string($data[$key] ?? null)) {
                return null;
            }
        }
        if (false === $at) {
            return null;
        }
        $sha = \is_string($data['sha'] ?? null) ? $data['sha'] : null;
        $backup = \is_string($data['backup'] ?? null) ? $data['backup'] : null;

        return new self($at, $data['event'], $data['result'], $data['from'], $data['to'], $sha, $data['actor'], $backup);
    }

    /**
     * @return array{ts: string, event: string, result: string, from: string, to: string, sha: ?string, actor: string, backup: ?string}
     */
    public function toArray(): array
    {
        return ['ts' => $this->at->format(\DATE_ATOM), 'event' => $this->event, 'result' => $this->result, 'from' => $this->from, 'to' => $this->to, 'sha' => $this->sha, 'actor' => $this->actor, 'backup' => $this->backup];
    }
}
