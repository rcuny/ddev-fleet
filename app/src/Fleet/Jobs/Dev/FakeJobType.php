<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Dev;

use App\Domain\Job\Job;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development/test only: the job `fleet:dev:fake-job` creates. It writes `lines` lines, `delay` milliseconds apart (each
 * `width` characters long when given), then ends, failed when `fail` is set. A retry repeats it with the same params.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class FakeJobType implements JobType
{
    public function name(): string
    {
        return 'fake';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $params = $job->params();
        $lines = max(1, min(1000, \is_int($params['lines'] ?? null) ? $params['lines'] : 30));
        $delay = max(0, min(5000, \is_int($params['delay'] ?? null) ? $params['delay'] : 200));
        $width = \is_int($params['width'] ?? null) ? max(0, min(2000, $params['width'])) : 0;

        for ($i = 1; $i <= $lines; ++$i) {
            $text = \sprintf('fake line %d of %d', $i, $lines);
            $length = \strlen($text);
            $log->line($width > $length ? $text.' '.str_repeat('x', $width - $length - 1) : $text);
            if ($delay > 0 && $i < $lines) {
                usleep($delay * 1000);
            }
        }

        if (true === ($params['fail'] ?? false)) {
            throw new JobFailed('Fake job failed on purpose.');
        }
    }
}
