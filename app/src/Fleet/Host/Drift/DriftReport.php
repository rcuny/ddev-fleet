<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

/**
 * What a drift check found (FLE-261): per role unchanged, changed (the tasks that would change something, with their
 * diff, plus the "DRIFT:" messages a role emits for what a check run cannot show as a changed task) or failed. Built
 * from the report the playbook's fleet_drift callback prints (install/ansible/callback_plugins/fleet_drift.py), with
 * every diff and message masked and capped, and stored as the host's last report. Schema 1: additive changes only.
 */
final readonly class DriftReport
{
    public const int SCHEMA = 1;
    public const string BEGIN = 'FLEET-DRIFT-REPORT-BEGIN';
    public const string END = 'FLEET-DRIFT-REPORT-END';

    public const string LIMITS = 'A check run cannot see steps that run a command, such as firewall rules: a host can differ there without this report saying so.';

    private const int MAX_ROLES = 50;
    private const int MAX_ENTRIES = 200;
    private const int MAX_DIFF = 8000;
    private const int MAX_TEXT = 2000;

    /**
     * @param list<array{role: string, status: string, changed: list<array{task: string, diff: string}>, drift: list<string>, failed: list<array{task: string, msg: string}>}> $roles
     */
    public function __construct(
        public \DateTimeImmutable $checkedAt,
        public string $ref,
        public array $roles,
    ) {
    }

    /**
     * @throws DriftReportInvalid when the output holds no report
     */
    public static function fromRunnerOutput(string $stdout, string $ref, \DateTimeImmutable $now): self
    {
        $begin = strpos($stdout, self::BEGIN."\n");
        $end = false === $begin ? false : strpos($stdout, "\n".self::END, $begin);
        if (false === $begin || false === $end) {
            throw new DriftReportInvalid('The playbook printed no drift report.');
        }
        $json = substr($stdout, $begin + \strlen(self::BEGIN) + 1, $end - $begin - \strlen(self::BEGIN) - 1);
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DriftReportInvalid('The drift report could not be read.');
        }
        if (!\is_array($data) || self::SCHEMA !== ($data['schema'] ?? null) || !\is_array($data['roles'] ?? null)) {
            throw new DriftReportInvalid('The drift report has an unknown shape.');
        }

        return new self($now, self::clean($ref, 100), self::roles($data['roles']));
    }

    /**
     * @param array<string, mixed> $data a stored report (toArray())
     *
     * @throws DriftReportInvalid
     */
    public static function fromArray(array $data): self
    {
        $at = \is_string($data['checkedAt'] ?? null) ? date_create_immutable($data['checkedAt']) : false;
        if (false === $at || self::SCHEMA !== ($data['schema'] ?? null) || !\is_array($data['roles'] ?? null)) {
            throw new DriftReportInvalid('The stored drift report has an unknown shape.');
        }

        return new self($at, self::clean($data['ref'] ?? '', 100), self::roles($data['roles']));
    }

    /**
     * @return array{schema: int, checkedAt: string, ref: string, drift: bool, failed: bool, limits: string, roles: list<array{role: string, status: string, changed: list<array{task: string, diff: string}>, drift: list<string>, failed: list<array{task: string, msg: string}>}>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'checkedAt' => $this->checkedAt->format(\DATE_ATOM),
            'ref' => $this->ref,
            'drift' => $this->hasDrift(),
            'failed' => $this->hasFailures(),
            'limits' => self::LIMITS,
            'roles' => $this->roles,
        ];
    }

    public function hasDrift(): bool
    {
        return [] !== $this->withStatus('changed');
    }

    public function hasFailures(): bool
    {
        return [] !== $this->withStatus('failed');
    }

    /**
     * @return list<array{role: string, status: string, changed: list<array{task: string, diff: string}>, drift: list<string>, failed: list<array{task: string, msg: string}>}>
     */
    public function withStatus(string $status): array
    {
        return array_values(array_filter($this->roles, static fn (array $r): bool => $status === $r['status']));
    }

    /**
     * @param array<mixed> $roles
     *
     * @return list<array{role: string, status: string, changed: list<array{task: string, diff: string}>, drift: list<string>, failed: list<array{task: string, msg: string}>}>
     */
    private static function roles(array $roles): array
    {
        $out = [];
        foreach (\array_slice(array_values($roles), 0, self::MAX_ROLES) as $role) {
            if (!\is_array($role)) {
                continue;
            }
            $changed = [];
            foreach (\array_slice(self::listOf($role['changed'] ?? null), 0, self::MAX_ENTRIES) as $entry) {
                $changed[] = ['task' => self::clean($entry['task'] ?? '', 300), 'diff' => self::clean($entry['diff'] ?? '', self::MAX_DIFF)];
            }
            $failed = [];
            foreach (\array_slice(self::listOf($role['failed'] ?? null), 0, self::MAX_ENTRIES) as $entry) {
                $failed[] = ['task' => self::clean($entry['task'] ?? '', 300), 'msg' => self::clean($entry['msg'] ?? '', self::MAX_TEXT)];
            }
            $drift = [];
            $messages = \is_array($role['drift'] ?? null) ? array_values($role['drift']) : [];
            foreach (\array_slice($messages, 0, self::MAX_ENTRIES) as $message) {
                $drift[] = self::clean($message, self::MAX_TEXT);
            }
            $out[] = [
                'role' => self::clean($role['role'] ?? '', 100),
                'status' => [] !== $failed ? 'failed' : ([] !== $changed || [] !== $drift ? 'changed' : 'unchanged'),
                'changed' => $changed,
                'drift' => $drift,
                'failed' => $failed,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_array(...))) : [];
    }

    private static function clean(mixed $value, int $max): string
    {
        $text = \is_string($value) ? $value : '';
        $text = DriftMask::text($text);
        // Control characters other than newline and tab never belong in a report.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = mb_scrub($text, 'UTF-8');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' ... (truncated)' : $text;
    }
}
