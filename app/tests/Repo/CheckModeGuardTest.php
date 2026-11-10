<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-280 (from the FLE-261 review): `host:drift` runs the playbook with --check, and a task with `check_mode: false`
 * runs for real in that run. So such a task must only read (probe, slurp, command/script probes) or touch a
 * private temporary file made by the role itself. A mutating module there would change the host during a "read-only"
 * check. This test fails on any other module with check_mode: false.
 */
final class CheckModeGuardTest extends TestCase
{
    /** Modules that only read or run a probe; `command`, `shell` and `script` are reviewed by hand (read-only by name). */
    private const array READ_ONLY = ['command', 'shell', 'script', 'slurp', 'stat', 'uri', 'find', 'getent', 'debug', 'set_fact', 'meta', 'assert', 'wait_for', 'tempfile'];

    /** Modules allowed only on the role's own temporary file (a registered `*.path` such as ddev_key_tmp.path). */
    private const array TEMP_ONLY = ['file', 'copy', 'get_url'];

    private const array NOT_MODULES = ['name', 'when', 'register', 'changed_when', 'failed_when', 'check_mode', 'become', 'become_user', 'loop', 'with_items', 'tags', 'no_log', 'vars', 'environment', 'retries', 'delay', 'until', 'ignore_errors', 'notify', 'args', 'loop_control', 'delegate_to', 'block', 'rescue', 'always', 'run_once', 'timeout', 'listen', 'any_errors_fatal'];

    public function testNoMutatingModuleRunsWithCheckModeFalse(): void
    {
        $violations = [];
        $seen = 0;
        foreach (glob(\dirname(__DIR__, 3).'/install/ansible/roles/*/tasks/*.yml') ?: [] as $file) {
            foreach (self::tasks(Yaml::parse((string) file_get_contents($file))) as $task) {
                if (false !== ($task['check_mode'] ?? null)) {
                    continue;
                }
                ++$seen;
                $module = preg_replace('/^ansible\.builtin\./', '', (string) (array_values(array_diff(array_keys($task), self::NOT_MODULES))[0] ?? '?'));
                $where = basename(\dirname($file, 2)).'/'.basename($file).': '.($task['name'] ?? '?').' ('.$module.')';
                if (\in_array($module, self::READ_ONLY, true)) {
                    continue;
                }
                $args = (array) ($task['ansible.builtin.'.$module] ?? $task[$module] ?? []);
                $target = (string) ($args['dest'] ?? $args['path'] ?? '');
                if (\in_array($module, self::TEMP_ONLY, true) && 1 === preg_match('/^\{\{\s*[a-z0-9_]+\.path\s*\}\}$/', $target)) {
                    continue;
                }
                $violations[] = $where;
            }
        }

        self::assertGreaterThan(30, $seen, 'the scan found the check_mode: false tasks');
        self::assertSame([], $violations, 'check_mode: false runs for real in a drift check; use only a read-only module or the role\'s own temp file');
    }

    public function testTheGuardCatchesAMutatingModule(): void
    {
        $tasks = iterator_to_array(self::tasks(Yaml::parse("- name: x\n  block:\n    - name: bad\n      ansible.builtin.lineinfile:\n        path: /etc/hosts\n      check_mode: false\n")), false);

        self::assertSame('bad', $tasks[0]['name']);
        self::assertNotContains('lineinfile', self::READ_ONLY);
        self::assertNotContains('lineinfile', self::TEMP_ONLY);
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private static function tasks(mixed $tasks): \Generator
    {
        foreach (\is_array($tasks) ? $tasks : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            foreach (['block', 'rescue', 'always'] as $section) {
                if (isset($task[$section])) {
                    yield from self::tasks($task[$section]);
                }
            }
            yield $task;
        }
    }
}
