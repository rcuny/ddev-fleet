<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Job\Job;
use App\Domain\Job\JobStatus;
use App\Fleet\Jobs\LogReader;
use App\Tests\Support\JobTestCase;

final class LogReaderTest extends JobTestCase
{
    private function jobWithLog(string $content, bool $final): Job
    {
        $job = $this->queuedJob();
        $path = $this->paths->jobLog((string) $job->id(), 'acme--main');
        @mkdir(\dirname($path), 0o750, true);
        file_put_contents($path, $content);
        if ($final) {
            $this->service->finish($job, JobStatus::Succeeded, 0, null);
        }

        return $job;
    }

    public function testStripsAnsiAndCollapsesCarriageReturns(): void
    {
        $job = $this->jobWithLog("\e[32mgreen\e[0m\nprogress 10%\rprogress 50%\rprogress 100%\nwin\r\nend\n", true);

        $read = $this->reader->read($job, 0);

        self::assertSame("green\nprogress 100%\nwin\nend\n", $read['text']);
        self::assertTrue($read['done']);
    }

    public function testStopsAtTheLastNewlineWhileRunning(): void
    {
        $job = $this->jobWithLog("one\ntwo\nthr", false);

        $read = $this->reader->read($job, 0);

        self::assertSame("one\ntwo\n", $read['text']);
        self::assertSame(8, $read['next']);
        self::assertFalse($read['done']);
    }

    public function testAHalfLineIsWaitedFor(): void
    {
        $job = $this->jobWithLog('no newline yet', false);

        $read = $this->reader->read($job, 0);

        self::assertSame(['text' => '', 'next' => 0, 'done' => false], $read);
    }

    public function testReturnsTheTailWhenFinal(): void
    {
        $job = $this->jobWithLog("one\ntail without newline", true);

        $read = $this->reader->read($job, 0);

        self::assertSame("one\ntail without newline", $read['text']);
        self::assertTrue($read['done']);
        self::assertSame(\strlen("one\ntail without newline"), $read['next']);
    }

    public function testOffsetBeyondEndIsEmpty(): void
    {
        $job = $this->jobWithLog("abc\n", true);

        $read = $this->reader->read($job, 500);

        self::assertSame('', $read['text']);
        self::assertSame(4, $read['next']);
        self::assertTrue($read['done']);
    }

    public function testMissingLogIsEmpty(): void
    {
        $job = $this->queuedJob();

        self::assertSame(['text' => '', 'next' => 0, 'done' => false], $this->reader->read($job, 0));
    }

    public function testChunkIsAtMost64KiB(): void
    {
        $job = $this->jobWithLog(str_repeat(str_repeat('x', 99)."\n", 1000), true);

        $first = $this->reader->read($job, 0);

        self::assertLessThanOrEqual(LogReader::CHUNK, \strlen($first['text']));
        self::assertFalse($first['done']);
        self::assertStringEndsWith("\n", $first['text']);

        $all = $first['text'];
        for ($next = $first['next'], $guard = 0; $guard < 10; ++$guard) {
            $piece = $this->reader->read($job, $next);
            $all .= $piece['text'];
            $next = $piece['next'];
            if ($piece['done']) {
                break;
            }
        }
        self::assertSame(100_000, \strlen($all));
    }

    public function testAHugeSingleLineIsSplit(): void
    {
        $job = $this->jobWithLog(str_repeat('é', 100_000), true);

        $first = $this->reader->read($job, 0);
        self::assertLessThanOrEqual(LogReader::CHUNK, \strlen($first['text']));
        self::assertGreaterThan(60_000, \strlen($first['text']));
        self::assertTrue(mb_check_encoding($first['text'], 'UTF-8'));
        self::assertFalse($first['done']);

        $all = $first['text'];
        $next = $first['next'];
        while (true) {
            $piece = $this->reader->read($job, $next);
            self::assertTrue(mb_check_encoding($piece['text'], 'UTF-8'));
            $all .= $piece['text'];
            $next = $piece['next'];
            if ($piece['done']) {
                break;
            }
        }
        self::assertSame(str_repeat('é', 100_000), $all);
    }

    public function testAHugeLineIsNotCutInsideAnEscapeSequence(): void
    {
        $content = str_repeat('a', LogReader::CHUNK - 2)."\e[31mred\e[0m".str_repeat('b', 10);
        $job = $this->jobWithLog($content, true);

        $first = $this->reader->read($job, 0);
        self::assertSame(LogReader::CHUNK - 2, $first['next'], 'the piece stops before the escape that straddles the cut');
        $second = $this->reader->read($job, $first['next']);

        self::assertSame(str_repeat('a', LogReader::CHUNK - 2).'redbbbbbbbbbb', $first['text'].$second['text']);
        self::assertStringNotContainsString("\e", $first['text'].$second['text']);
    }
}
