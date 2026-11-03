<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The installed version cannot predict what the new one needs (ruling R6), so the target checks itself: its
 * `install/run.sh --preflight` runs from a copy of the tag and prints `BLOCK|WARN|INFO <id>: <message>` per finding.
 * Each line becomes a finding with the id prefixed `installer.`. The target's own refusal stands: its blocks cannot
 * be waived from here.
 *
 * Findings: `installer.<id>` (the line's severity, Block not overridable), `installer.unsupported` (Info) when the
 * target predates the flag, `installer.unavailable` (Info) when the target could not be fetched, and
 * `installer.failed` (Block) when the pre-flight ran but exited non-zero without a BLOCK line.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 100])]
final readonly class InstallerPreflightCheck implements UpgradeCheck
{
    public const string UNSUPPORTED = 'installer.unsupported';
    public const string UNAVAILABLE = 'installer.unavailable';
    public const string FAILED = 'installer.failed';

    public function __construct(private TargetInstallerSource $source, private CommandRunner $runner, private string $defaultUri)
    {
    }

    public function check(Plan $plan): array
    {
        if (null === $plan->target) {
            return [];
        }
        $dir = $this->source->fetch($plan->target);
        if (null === $dir) {
            return [CheckResult::info(self::UNAVAILABLE, \sprintf('The installer of %s could not be fetched, so its own pre-flight did not run.', $plan->target->tag()))];
        }

        try {
            $run = $dir.'/install/run.sh';
            $text = is_file($run) ? (string) file_get_contents($run) : '';
            if (!str_contains($text, '--preflight')) {
                return [CheckResult::info(self::UNSUPPORTED, \sprintf('%s predates the installer pre-flight; only the checks of the installed version ran.', $plan->target->tag()))];
            }

            $argv = ['bash', $run, '--preflight', '--ref='.$plan->target->tag(), '--skip-dns-check'];
            $host = parse_url($this->defaultUri, \PHP_URL_HOST);
            if (\is_string($host) && '' !== $host && 'localhost' !== $host) {
                $argv[] = '--domain='.$host;
            }
            $result = $this->runner->run($argv, cwd: $dir, timeout: 120.0);
        } catch (\Throwable $e) {
            return [CheckResult::block(self::FAILED, 'The target installer pre-flight could not run: '.$e->getMessage(), 'Run the check again.')];
        } finally {
            $this->source->discard($dir);
        }

        $results = [];
        foreach (explode("\n", $result->stdout) as $line) {
            if (1 !== preg_match('/^(BLOCK|WARN|INFO)\s+([a-z0-9._-]+):\s*(.*)$/D', trim($line), $m)) {
                continue;
            }
            $id = 'installer.'.$m[2];
            $results[] = match ($m[1]) {
                'BLOCK' => CheckResult::block($id, $m[3], 'Resolve it as the message says and run the check again.'),
                'WARN' => CheckResult::warn($id, $m[3], '', false),
                default => CheckResult::info($id, $m[3]),
            };
        }
        if (!$result->isSuccess() && [] === array_filter($results, static fn (CheckResult $r): bool => Severity::Block === $r->severity)) {
            $results[] = CheckResult::block(self::FAILED, \sprintf('The target installer pre-flight exited %d without naming a problem.', $result->exitCode), 'Run `bash install/run.sh --preflight` from the target tag to see why.');
        }

        return $results;
    }
}
