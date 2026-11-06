<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\Check\CheckResult;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\UpgradeAssessment;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The text form of an UpgradeAssessment (the JSON form is UpgradeAssessment::toArray()). Everything from the changelog
 * and the checks is untrusted text: it is written raw (no console formatting) with control characters removed, and the
 * changelog excerpt is capped.
 */
final class AssessmentPrinter
{
    /** Changelog lines shown before "... N more lines"; --json carries the whole text. */
    public const int MAX_CHANGELOG_LINES = 60;

    public function print(OutputInterface $output, UpgradeAssessment $a): void
    {
        $plan = $a->plan;
        $line = static fn (string $text) => $output->writeln(self::clean($text), OutputInterface::OUTPUT_RAW);

        $line('Installed: '.$plan->from.(null === $plan->fromVersion ? ' (not a release)' : ''));
        $latest = $a->latest;
        $line('Latest:    '.(null === $latest ? 'unknown' : $latest->tag()).self::catalogNote($a));

        if (null !== $a->catalog->error) {
            $line('');
            $line('Could not read the release source: '.$a->catalog->error);
            $line(null === $latest ? 'No cached release list. Connect the host and run it again.' : 'Showing the cached list; the answer may be out of date.');

            return;
        }
        if (null !== $a->changelogError) {
            $line('');
            $line('Could not read the changelog: '.$a->changelogError);

            return;
        }
        if (!$plan->hasTarget()) {
            $line('');
            $line('No release is known yet.');

            return;
        }
        \assert(null !== $plan->target);

        $line('Target:    '.$plan->target->tag());
        $line('');
        match ($plan->direction) {
            Direction::Same => $line('Already up to date.'),
            Direction::Unknown => $line('This host does not run a release, so there is nothing to compare with. Target: '.$plan->target->tag().'.'),
            Direction::Down => $line('This is a downgrade: '.$plan->from.' -> '.$plan->target->tag().'. See docs/reference/upgrade.md before running it.'),
            Direction::Up => $line(\sprintf('Update available: %s -> %s (%d release%s).', $plan->from, $plan->target->tag(), \count($a->releases), 1 === \count($a->releases) ? '' : 's')),
        };

        $this->changelog($output, $a);
        $this->rollout($output, $a);
        $this->checks($output, $a);

        if (UpgradeAssessment::BLOCKED === $a->status()) {
            $line('');
            $line('Blocked: fix the findings marked BLOCK, then run the check again. Nothing was changed.');
        } elseif (Direction::Up === $plan->direction) {
            $line('');
            $line('To upgrade: sudo fleet upgrade'.(null === $latest || $latest->tag() === $plan->target->tag() ? '' : ' --to '.$plan->target->tag()));
        }
    }

    private function changelog(OutputInterface $output, UpgradeAssessment $a): void
    {
        if ([] === $a->changelog) {
            return;
        }
        $output->writeln('', OutputInterface::OUTPUT_RAW);
        $output->writeln('Changelog', OutputInterface::OUTPUT_RAW);
        $budget = self::MAX_CHANGELOG_LINES;
        $skipped = 0;
        foreach ($a->changelog as $section) {
            $header = '## '.$section->version->tag().(null === $section->date ? '' : ' - '.$section->date);
            $lines = array_merge([$header], '' === $section->body ? [] : explode("\n", $section->body), ['']);
            foreach ($lines as $text) {
                if ($budget > 0) {
                    $output->writeln(self::clean($text), OutputInterface::OUTPUT_RAW);
                    --$budget;
                } else {
                    ++$skipped;
                }
            }
        }
        if ($skipped > 0) {
            $output->writeln(\sprintf('... %d more lines (--json has the whole text)', $skipped), OutputInterface::OUTPUT_RAW);
        }
    }

    private function rollout(OutputInterface $output, UpgradeAssessment $a): void
    {
        $notes = $a->rolloutNotes();
        if ([] === $notes) {
            return;
        }
        $output->writeln('Rollout notes', OutputInterface::OUTPUT_RAW);
        foreach ($notes as $note) {
            $output->writeln(self::clean('  - '.$note), OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    private function checks(OutputInterface $output, UpgradeAssessment $a): void
    {
        $checks = $a->checks;
        if (null === $checks) {
            return;
        }
        $output->writeln('Pre-flight checks', OutputInterface::OUTPUT_RAW);
        if ([] === $checks->results) {
            $output->writeln('  all passed', OutputInterface::OUTPUT_RAW);

            return;
        }
        foreach ($checks->results as $r) {
            $output->writeln(self::clean(\sprintf('  %-5s %s: %s', strtoupper($r->severity->value), $r->id, $r->message)), OutputInterface::OUTPUT_RAW);
            if ('' !== $r->remedy) {
                $output->writeln(self::clean('        -> '.$r->remedy.self::overridable($r)), OutputInterface::OUTPUT_RAW);
            }
        }
    }

    private static function overridable(CheckResult $r): string
    {
        return $r->overridable && \App\Fleet\Upgrade\Check\Severity::Block === $r->severity ? ' (can be waived with --force='.$r->id.')' : '';
    }

    private static function catalogNote(UpgradeAssessment $a): string
    {
        $age = $a->ageSeconds();
        if (null === $age) {
            return '';
        }

        return \sprintf(' (listed %s ago%s)', self::duration($age), $a->refreshed && null === $a->catalog->error ? ', just now' : '');
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => $seconds.' s',
            $seconds < 5400 => (int) round($seconds / 60).' min',
            $seconds < 129600 => (int) round($seconds / 3600).' h',
            default => (int) round($seconds / 86400).' days',
        };
    }

    private static function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);
    }
}
