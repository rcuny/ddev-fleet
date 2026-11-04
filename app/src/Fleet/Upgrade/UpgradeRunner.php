<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Upgrade\Check\SchemaAheadCheck;

/**
 * The fleet-user phases of `sudo fleet upgrade` (the root helper drives them in this order, ruling R2):
 *
 *   prepare()  the plan and the pre-flight checks. Refuses (exit 3) a Block, an unreachable release source, a downgrade
 *              or a host that runs no release. Changes nothing.
 *   backup()   the pre-upgrade database backup; a failure stops the upgrade before the installer (exit 1).
 *   verify()   after the installer: UpgradeVerifier; a failure is exit 5 with the rollback command.
 *
 * The installer itself runs as root in the helper; the history file is appended there too (it is root-owned).
 */
final readonly class UpgradeRunner
{
    public const int EXIT_VERIFY_FAILED = 5;

    public function __construct(
        private UpgradeAssessor $assessor,
        private UpgradeBackup $backups,
        private UpgradeVerification $verifier,
        private ?UpgradeRestore $restorer = null,
        private ?UpgradeHistory $history = null,
        private RollbackPlanner $rollbacks = new RollbackPlanner(),
    ) {
    }

    /**
     * @param list<string> $force check ids to waive (overridable Blocks only)
     */
    public function prepare(?string $to, array $force = [], bool $noDbRestore = false): Preparation
    {
        $asked = null === $to ? null : Version::tryParse($to);
        if (null !== $asked && 0 === $asked->major) {
            return new Preparation(RunState::Refused, reasons: ['The v0.* series is a separate product line and is never offered: a rollback stays inside the installed major version.']);
        }
        try {
            $assessment = $this->assessor->assess($to, true, $force);
        } catch (\InvalidArgumentException $e) {
            return new Preparation(RunState::Usage, reasons: [$e->getMessage()]);
        } catch (\Throwable $e) {
            return new Preparation(RunState::Failed, reasons: ['The upgrade plan failed: '.$e->getMessage()]);
        }

        $from = $assessment->plan->from;
        $target = $assessment->plan->target;
        $refuse = static fn (string ...$reasons): Preparation => new Preparation(RunState::Refused, $from, $target, array_values($reasons), [], $assessment);

        switch ($assessment->status()) {
            case UpgradeAssessment::OFFLINE:
                return $refuse('The release source could not be read, and the upgrade needs it (the installer clones the release). Try again when GitHub is reachable.');
            case UpgradeAssessment::NOT_A_RELEASE:
                return $refuse(\sprintf('This host runs "%s", not a release: there is nothing to compare with. Install a release with the installer (docs/installation.md, section 6).', $from));
            case UpgradeAssessment::DOWNGRADE:
                return $this->prepareRollback($assessment, $from, $noDbRestore);
            case UpgradeAssessment::UP_TO_DATE:
                return new Preparation(RunState::UpToDate, $from, $target, [], [], $assessment);
            case UpgradeAssessment::BLOCKED:
                $reasons = [];
                foreach ($assessment->checks?->blocking() ?? [] as $result) {
                    $reasons[] = \sprintf('[%s] %s%s', $result->id, $result->message, '' !== $result->remedy ? ' '.$result->remedy : '');
                }

                return $refuse(...($reasons ?: ['A pre-flight check blocks the upgrade.']));
        }

        return new Preparation(RunState::Ready, $from, $target, [], $assessment->rolloutActions(), $assessment);
    }

    private function prepareRollback(UpgradeAssessment $assessment, string $from, bool $noDbRestore): Preparation
    {
        $target = $assessment->plan->target;
        $installed = $assessment->plan->fromVersion;
        \assert(null !== $target && null !== $installed);
        if (null === $this->restorer || null === $this->history) {
            return new Preparation(RunState::Refused, $from, $target, ['A rollback is not available here: no restore or history is configured.'], [], $assessment);
        }
        $rollback = $this->rollbacks->plan(
            $installed,
            $target->version,
            $this->history->entries(5000),
            $this->restorer->backupNames(),
            null !== $assessment->checks?->find(SchemaAheadCheck::AHEAD),
            $noDbRestore,
        );
        if (!$rollback->ok()) {
            return new Preparation(RunState::Refused, $from, $target, $rollback->refusals, [], $assessment, $rollback);
        }

        return new Preparation(RunState::Ready, $from, $target, [], [], $assessment, $rollback);
    }

    /**
     * @throws \RuntimeException when the restore is refused or fails
     */
    public function restore(string $backup): void
    {
        ($this->restorer ?? throw new \RuntimeException('No restore is configured.'))->restore($backup);
    }

    /**
     * @return string the backup file name (kept in the history)
     *
     * @throws \RuntimeException when the backup cannot be taken; nothing partial is left behind
     */
    public function backup(): string
    {
        return $this->backups->create();
    }

    public function verify(string $from, string $targetTag, ?string $sha, string $actor): VerifyReport
    {
        return $this->verifier->verify($from, $targetTag, $sha, $actor);
    }
}
