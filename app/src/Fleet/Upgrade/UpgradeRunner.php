<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

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
    ) {
    }

    /**
     * @param list<string> $force check ids to waive (overridable Blocks only)
     */
    public function prepare(?string $to, array $force = []): Preparation
    {
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
                return $refuse('The target is older than the installed version. Going back is a rollback, which this command does not do yet.');
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
