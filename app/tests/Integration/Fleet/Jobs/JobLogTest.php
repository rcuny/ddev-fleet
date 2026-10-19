<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\LogMasker;
use App\Fleet\Jobs\TailAwareLogMasker;
use App\Tests\Support\JobTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class JobLogTest extends JobTestCase
{
    public function testMasksThroughTheMasker(): void
    {
        $job = $this->queuedJob();
        $masker = new class implements LogMasker {
            public function mask(string $text): string
            {
                return str_replace('hunter2', '***', $text);
            }
        };
        $path = $this->paths->jobLog((string) $job->id(), 'acme--main');
        $log = new JobLog($path, $masker, $this->feed, $job);

        $log->output('stdout', "token hunter2\n");
        $log->line('also hunter2');

        $text = (string) file_get_contents($path);
        self::assertStringNotContainsString('hunter2', $text);
        self::assertStringContainsString("token ***\n", $text);
        self::assertSame(\strlen($text), $log->size());
        self::assertMatchesRegularExpression('/^token \*\*\*\n\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\] also \*\*\*\n$/', $text);
    }

    public function testHubFailureNeverBreaksTheJob(): void
    {
        $job = $this->queuedJob();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub is down'));
        $path = $this->paths->jobLog((string) $job->id(), 'acme--main');
        $log = new JobLog($path, new \App\Fleet\Jobs\NoLogMasker(), new JobFeed($hub, new NullLogger()), $job);

        $log->line('still written');

        self::assertStringContainsString('still written', (string) file_get_contents($path));
    }

    public function testSizeContinuesAnExistingFile(): void
    {
        $job = $this->queuedJob();
        $path = $this->paths->jobLog((string) $job->id(), 'acme--main');
        $first = new JobLog($path, new \App\Fleet\Jobs\NoLogMasker(), $this->feed, $job);
        $first->output('stdout', "abc\n");

        $second = new JobLog($path, new \App\Fleet\Jobs\NoLogMasker(), $this->feed, $job);

        self::assertSame(4, $second->size());
    }

    public function testASecretSplitAcrossTwoChunksIsMasked(): void
    {
        $job = $this->queuedJob();
        $masker = new class implements TailAwareLogMasker {
            public function mask(string $text): string
            {
                return str_replace('hunter2', '***', $text);
            }

            public function longestSecret(): int
            {
                return 7;
            }
        };
        $path = $this->paths->jobLog((string) $job->id(), 'acme--main');
        $log = new JobLog($path, $masker, $this->feed, $job);

        $log->output('stdout', 'password hun');
        self::assertStringNotContainsString('hun', (string) @file_get_contents($path), 'the possible start of a secret is held back');
        $log->output('stdout', "ter2 and more\n");
        $log->output('stdout', 'tail hunt');
        $log->finish();

        $text = (string) file_get_contents($path);
        self::assertSame("password *** and more\ntail hunt", $text);
        self::assertSame(\strlen($text), $log->size());
    }
}
