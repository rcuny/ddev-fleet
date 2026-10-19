<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Assets;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Assets\AssetStore;
use App\Fleet\Assets\UploadService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Chunked uploads against a real FLEET_HOME and the real host settings row: assembly in any order, duplicates, size
 * mismatches, the cap, resuming, concurrent uploads, abandoned uploads, and a destination swapped for a symlink.
 */
final class UploadServiceTest extends KernelTestCase
{
    use FleetDatabase;

    private const int CHUNK = 65_536;

    private FleetPaths $paths;
    private UploadService $uploads;
    private MockClock $clock;
    private HostSettingsRepository $settings;
    private string $outside;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $settings = self::getContainer()->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $settings);
        $this->settings = $settings;
        $this->settings->save(new HostSettings('fleet.example.com'));

        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->uploads = new UploadService($this->paths, new AssetStore($this->paths), $this->settings, $this->clock);

        $this->outside = sys_get_temp_dir().'/fleet-outside-'.bin2hex(random_bytes(5));
        mkdir($this->outside, 0o755);
        file_put_contents($this->outside.'/secret.txt', 'original');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outside.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outside);
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private static function bytes(int $length, int $seed = 1): string
    {
        $out = '';
        while (\strlen($out) < $length) {
            $out .= hash('sha256', $seed.':'.\strlen($out), true);
        }

        return substr($out, 0, $length);
    }

    private function assets(string $relative = ''): string
    {
        return $this->paths->assetsDir('blog').('' === $relative ? '' : '/'.$relative);
    }

    /**
     * @param list<int>|null $order
     */
    private function upload(string $data, string $name = 'db.sql', string $dir = '', ?array $order = null, bool $overwrite = false, string $project = 'blog'): \App\Fleet\Assets\UploadResult
    {
        $state = $this->uploads->begin($project, $dir, $name, \strlen($data), self::CHUNK, '', $overwrite);
        foreach ($order ?? range(0, $state->chunks - 1) as $index) {
            $this->uploads->putChunk($project, $state->id, $index, substr($data, $index * $state->chunkSize, $state->chunkSize));
        }

        return $this->uploads->complete($project, $state->id);
    }

    private function stagedCount(): int
    {
        return \count(glob($this->paths->uploadsDir().'/*') ?: []);
    }

    // --- assembly

    public function testChunksInOrderAssembleTheFileAndReportItsSha256(): void
    {
        $data = self::bytes(self::CHUNK * 3 + 1234);

        $result = $this->upload($data);

        self::assertSame($data, file_get_contents($this->assets('db.sql')));
        self::assertSame(hash('sha256', $data), $result->sha256);
        self::assertSame(\strlen($data), $result->size);
        self::assertSame('db.sql', $result->path);
        clearstatcache();
        self::assertSame(0o640, fileperms($this->assets('db.sql')) & 0o777);
        self::assertSame(0, $this->stagedCount(), 'the staging directory is gone');
        self::assertSame(['.', '..', 'db.sql'], scandir($this->assets()), 'no temporary file is left next to the target');
    }

    public function testChunksInAnyOrderGiveTheSameFile(): void
    {
        $data = self::bytes(self::CHUNK * 4 + 7, 2);

        $result = $this->upload($data, 'shuffled.sql', 'dumps/2026', [4, 0, 3, 1, 2]);

        self::assertSame($data, file_get_contents($this->assets('dumps/2026/shuffled.sql')));
        self::assertSame(hash('sha256', $data), $result->sha256);
        self::assertSame('dumps/2026/shuffled.sql', $result->path);
    }

    public function testASingleChunkSmallFileWorks(): void
    {
        $result = $this->upload('SELECT 1;', 'tiny.sql');

        self::assertSame('SELECT 1;', file_get_contents($this->assets('tiny.sql')));
        self::assertSame(hash('sha256', 'SELECT 1;'), $result->sha256);
    }

    public function testADuplicateChunkIsHarmlessAndAResentChunkReplacesTheFirst(): void
    {
        $data = self::bytes(self::CHUNK * 2 + 5, 3);
        $state = $this->uploads->begin('blog', '', 'dup.sql', \strlen($data), self::CHUNK);

        $this->uploads->putChunk('blog', $state->id, 1, substr($data, self::CHUNK, self::CHUNK));
        $again = $this->uploads->putChunk('blog', $state->id, 1, substr($data, self::CHUNK, self::CHUNK));
        self::assertSame([1], $again->received);
        // A different payload of the right length replaces the stored one; the last write wins.
        $this->uploads->putChunk('blog', $state->id, 0, str_repeat('x', self::CHUNK));
        $this->uploads->putChunk('blog', $state->id, 0, substr($data, 0, self::CHUNK));
        $this->uploads->putChunk('blog', $state->id, 2, substr($data, 2 * self::CHUNK));

        $result = $this->uploads->complete('blog', $state->id);

        self::assertSame($data, file_get_contents($this->assets('dup.sql')));
        self::assertSame(hash('sha256', $data), $result->sha256);
    }

    public function testStateListsTheChunksTheServerHolds(): void
    {
        $state = $this->uploads->begin('blog', '', 'p.sql', self::CHUNK * 3, self::CHUNK);
        self::assertSame(3, $state->chunks);
        self::assertSame(self::CHUNK, $state->chunkSize);
        self::assertSame([], $state->received);
        self::assertFalse($state->isComplete());

        $this->uploads->putChunk('blog', $state->id, 2, str_repeat('a', self::CHUNK));
        $this->uploads->putChunk('blog', $state->id, 0, str_repeat('a', self::CHUNK));

        $status = $this->uploads->status('blog', $state->id);
        self::assertSame([0, 2], $status->received);
        self::assertSame(['id', 'name', 'path', 'size', 'chunkSize', 'chunks', 'received'], array_keys($status->toArray()));
    }

    // --- size checks

    public function testAChunkOfTheWrongLengthIsRefusedAndStoresNothing(): void
    {
        $state = $this->uploads->begin('blog', '', 'm.sql', self::CHUNK * 2 + 10, self::CHUNK);

        foreach ([
            [0, str_repeat('a', self::CHUNK - 1)],
            [0, str_repeat('a', self::CHUNK + 1)],
            [0, ''],
            [2, str_repeat('a', 11)],
            [2, str_repeat('a', 9)],
        ] as [$index, $body]) {
            try {
                $this->uploads->putChunk('blog', $state->id, $index, $body);
                self::fail(\sprintf('Accepted %d bytes as chunk %d.', \strlen($body), $index));
            } catch (ValidationFailed $failed) {
                self::assertSame(['chunk'], array_keys($failed->errors));
            }
        }
        self::assertSame([], $this->uploads->status('blog', $state->id)->received);
        self::assertSame(['.', '..', 'meta.json'], scandir($this->paths->uploadDir($state->id)), 'no temporary chunk file stays behind');
    }

    public function testAChunkIndexOutsideTheUploadIsRefused(): void
    {
        $state = $this->uploads->begin('blog', '', 'i.sql', self::CHUNK, self::CHUNK);

        foreach ([-1, 1, 99999] as $index) {
            try {
                $this->uploads->putChunk('blog', $state->id, $index, str_repeat('a', self::CHUNK));
                self::fail('Accepted chunk '.$index);
            } catch (ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCompletingWithAChunkMissingIsRefusedAndKeepsTheOthers(): void
    {
        $state = $this->uploads->begin('blog', '', 'gap.sql', self::CHUNK * 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, str_repeat('a', self::CHUNK));
        $this->uploads->putChunk('blog', $state->id, 2, str_repeat('a', self::CHUNK));

        try {
            $this->uploads->complete('blog', $state->id);
            self::fail('Completed with a hole.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::INCOMPLETE, $refusal->getCode());
            self::assertStringContainsString('chunk 1 is missing', $refusal->getMessage());
        }
        self::assertFileDoesNotExist($this->assets('gap.sql'));

        // Send the hole and finish: the upload survived the refusal.
        $this->uploads->putChunk('blog', $state->id, 1, str_repeat('a', self::CHUNK));
        $this->uploads->complete('blog', $state->id);
        self::assertSame(self::CHUNK * 3, filesize($this->assets('gap.sql')));
    }

    // --- begin: cap and validation

    public function testTheDefaultCapIsTwoGiB(): void
    {
        self::assertSame(2_147_483_648, $this->uploads->maxBytes());

        $this->expectException(Refused::class);
        $this->expectExceptionCode(UploadService::TOO_LARGE);
        $this->uploads->begin('blog', '', 'huge.sql', 2_147_483_649);
    }

    public function testALoweredCapProducesTheLimitMessage(): void
    {
        $host = $this->settings->get();
        self::assertNotNull($host);
        $host->changeAssetUploadMaxBytes(100_000);
        $this->settings->save($host);

        $this->uploads->begin('blog', '', 'exact.sql', 100_000, self::CHUNK);
        try {
            $this->uploads->begin('blog', '', 'over.sql', 100_001, self::CHUNK);
            self::fail('Exceeded the cap.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::TOO_LARGE, $refusal->getCode());
            self::assertStringContainsString('upload limit of 97.7 KiB', $refusal->getMessage());
            self::assertStringContainsString($this->paths->assetsDir('blog'), $refusal->getMessage());
        }
    }

    public function testTheCapDefaultsWithoutAHostRow(): void
    {
        $em = $this->entityManager();
        $em->createQuery('DELETE FROM '.HostSettings::class)->execute();
        $em->clear();

        self::assertSame(HostSettings::DEFAULT_ASSET_UPLOAD_MAX_BYTES, $this->uploads->maxBytes());
    }

    public function testBeginRefusesBadInput(): void
    {
        foreach ([
            ['', 'a.sql', 0],
            ['', 'a.sql', -5],
            ['', '../a.sql', 10],
            ['', 'a/b.sql', 10],
            ['', "a\0.sql", 10],
            ['', '.fleet-upload-x.tmp', 10],
            ['../x', 'a.sql', 10],
            ['/abs', 'a.sql', 10],
            ["d\0", 'a.sql', 10],
        ] as [$dir, $name, $size]) {
            try {
                $this->uploads->begin('blog', $dir, $name, $size);
                self::fail('Accepted '.json_encode([$dir, $name, $size], \JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->uploads->begin('blog', '', 'a.sql', 10, null, str_repeat('f', 513));
            self::fail('Accepted an oversized fingerprint.');
        } catch (ValidationFailed $failed) {
            self::assertSame(['fingerprint'], array_keys($failed->errors));
        }
        self::assertSame(0, $this->stagedCount());
        self::assertDirectoryDoesNotExist($this->assets(), 'a refused upload creates nothing');
    }

    public function testTheChunkSizeIsClampedToTheAllowedRange(): void
    {
        self::assertSame(UploadService::MAX_CHUNK_BYTES, $this->uploads->begin('blog', '', 'a.sql', 100)->chunkSize);
        self::assertSame(UploadService::MAX_CHUNK_BYTES, $this->uploads->begin('blog', '', 'b.sql', 100, 999_999_999)->chunkSize);
        self::assertSame(UploadService::MIN_CHUNK_BYTES, $this->uploads->begin('blog', '', 'c.sql', 100, 1)->chunkSize);
        self::assertSame(3, $this->uploads->begin('blog', '', 'd.sql', UploadService::MAX_CHUNK_BYTES * 2 + 1)->chunks);
    }

    public function testUnicodeNamesWork(): void
    {
        $this->upload('é', 'données été.sql', 'sauvegardes');

        self::assertFileExists($this->assets('sauvegardes/données été.sql'));
    }

    // --- existing targets

    public function testAnExistingFileIsNotReplacedWithoutOverwrite(): void
    {
        $this->upload('old', 'db.sql');

        try {
            $this->uploads->begin('blog', '', 'db.sql', 3);
            self::fail('Planned to replace a file without being told to.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::CONFLICT, $refusal->getCode());
            self::assertSame(AssetStore::REASON_FILE_EXISTS, $refusal->reason);
        }
        self::assertSame('old', file_get_contents($this->assets('db.sql')));

        $this->upload('new', 'db.sql', '', null, true);
        self::assertSame('new', file_get_contents($this->assets('db.sql')));
    }

    public function testAFileAppearingAfterBeginIsNotClobbered(): void
    {
        $state = $this->uploads->begin('blog', '', 'race.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, 'new');
        file_put_contents($this->assets('race.sql'), 'someone else');

        try {
            $this->uploads->complete('blog', $state->id);
            self::fail('Clobbered a file that appeared meanwhile.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::CONFLICT, $refusal->getCode());
        }
        self::assertSame('someone else', file_get_contents($this->assets('race.sql')));
        self::assertSame(['.', '..', 'race.sql'], scandir($this->assets()));
    }

    public function testAFolderAtTheTargetIsRefused(): void
    {
        mkdir($this->assets('dir'), 0o750, true);

        try {
            $this->uploads->begin('blog', '', 'dir', 10, null, '', true);
            self::fail('Planned to upload over a folder.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::CONFLICT, $refusal->getCode());
            // Not replaceable: the browser must not offer "Replace it?".
            self::assertNull($refusal->reason);
        }
    }

    // --- resuming

    public function testBeginWithTheSameFingerprintResumesTheSameUpload(): void
    {
        $first = $this->uploads->begin('blog', 'd', 'big.sql', self::CHUNK * 2, self::CHUNK, 'big.sql:131072:1');
        $this->uploads->putChunk('blog', $first->id, 1, str_repeat('b', self::CHUNK));

        $second = $this->uploads->begin('blog', 'd', 'big.sql', self::CHUNK * 2, self::CHUNK, 'big.sql:131072:1');

        self::assertSame($first->id, $second->id);
        self::assertSame([1], $second->received);
        self::assertSame(1, $this->stagedCount());

        // A different file (fingerprint), size, folder or project starts a fresh upload.
        self::assertNotSame($first->id, $this->uploads->begin('blog', 'd', 'big.sql', self::CHUNK * 2, self::CHUNK, 'big.sql:131072:2')->id);
        self::assertNotSame($first->id, $this->uploads->begin('blog', 'e', 'big.sql', self::CHUNK * 2, self::CHUNK, 'big.sql:131072:1')->id);
        self::assertNotSame($first->id, $this->uploads->begin('shop', 'd', 'big.sql', self::CHUNK * 2, self::CHUNK, 'big.sql:131072:1')->id);
        // No fingerprint, no resume.
        $a = $this->uploads->begin('blog', 'd', 'x.sql', 10);
        self::assertNotSame($a->id, $this->uploads->begin('blog', 'd', 'x.sql', 10)->id);
    }

    public function testAnUploadBelongsToItsProject(): void
    {
        $state = $this->uploads->begin('blog', '', 'mine.sql', 10);

        foreach ([
            fn () => $this->uploads->status('shop', $state->id),
            fn () => $this->uploads->putChunk('shop', $state->id, 0, str_repeat('a', 10)),
            fn () => $this->uploads->complete('shop', $state->id),
            fn () => $this->uploads->abort('shop', $state->id),
        ] as $call) {
            try {
                $call();
                self::fail('Used another project\'s upload.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNoSuchUploadAndMalformedIdsAreNotFound(): void
    {
        foreach (['', 'nope', str_repeat('0', 32), '../../etc', str_repeat('A', 32), str_repeat('0', 31)."\n"] as $id) {
            try {
                $this->uploads->status('blog', $id);
                self::fail((string) json_encode($id));
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- concurrency

    public function testInterleavedUploadsOfDifferentFilesDoNotMix(): void
    {
        $a = self::bytes(self::CHUNK * 3 + 11, 10);
        $b = self::bytes(self::CHUNK * 2 + 22, 11);
        $sa = $this->uploads->begin('blog', '', 'a.sql', \strlen($a), self::CHUNK);
        $sb = $this->uploads->begin('blog', '', 'b.sql', \strlen($b), self::CHUNK);

        $this->uploads->putChunk('blog', $sa->id, 2, substr($a, 2 * self::CHUNK, self::CHUNK));
        $this->uploads->putChunk('blog', $sb->id, 0, substr($b, 0, self::CHUNK));
        $this->uploads->putChunk('blog', $sa->id, 0, substr($a, 0, self::CHUNK));
        $this->uploads->putChunk('blog', $sb->id, 1, substr($b, self::CHUNK, self::CHUNK));
        $this->uploads->putChunk('blog', $sa->id, 3, substr($a, 3 * self::CHUNK));
        $this->uploads->putChunk('blog', $sa->id, 1, substr($a, self::CHUNK, self::CHUNK));
        $this->uploads->putChunk('blog', $sb->id, 2, substr($b, 2 * self::CHUNK));

        $this->uploads->complete('blog', $sb->id);
        $this->uploads->complete('blog', $sa->id);

        self::assertSame($a, file_get_contents($this->assets('a.sql')));
        self::assertSame($b, file_get_contents($this->assets('b.sql')));
    }

    public function testTwoUploadsToTheSameTargetLeaveExactlyOneWholeFile(): void
    {
        $a = self::bytes(self::CHUNK * 2 + 1, 20);
        $b = self::bytes(self::CHUNK * 2 + 1, 21);
        $sa = $this->uploads->begin('blog', '', 'same.sql', \strlen($a), self::CHUNK, '', true);
        $sb = $this->uploads->begin('blog', '', 'same.sql', \strlen($b), self::CHUNK, '', true);
        foreach ([0, 1, 2] as $index) {
            $this->uploads->putChunk('blog', $sa->id, $index, substr($a, $index * self::CHUNK, self::CHUNK));
            $this->uploads->putChunk('blog', $sb->id, $index, substr($b, $index * self::CHUNK, self::CHUNK));
        }

        $this->uploads->complete('blog', $sa->id);
        $this->uploads->complete('blog', $sb->id);

        self::assertSame($b, file_get_contents($this->assets('same.sql')), 'the last upload to finish wins, whole');
        self::assertSame(['.', '..', 'same.sql'], scandir($this->assets()));
        self::assertSame(0, $this->stagedCount());
    }

    public function testTwoUploadsToTheSameTargetWithoutOverwriteLetOnlyOneIn(): void
    {
        $sa = $this->uploads->begin('blog', '', 'once.sql', 3, self::CHUNK);
        $sb = $this->uploads->begin('blog', '', 'once.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $sa->id, 0, 'aaa');
        $this->uploads->putChunk('blog', $sb->id, 0, 'bbb');

        $this->uploads->complete('blog', $sa->id);
        try {
            $this->uploads->complete('blog', $sb->id);
            self::fail('The second upload replaced the first.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::CONFLICT, $refusal->getCode());
        }
        self::assertSame('aaa', file_get_contents($this->assets('once.sql')));
    }

    public function testCompletingTwiceFindsNothingTheSecondTime(): void
    {
        $state = $this->uploads->begin('blog', '', 'twice.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, 'abc');
        $this->uploads->complete('blog', $state->id);

        $this->expectException(NotFound::class);
        $this->uploads->complete('blog', $state->id);
    }

    public function testACompletionInProgressIsReportedBusy(): void
    {
        $state = $this->uploads->begin('blog', '', 'busy.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, 'abc');
        $other = fopen($this->paths->uploadDir($state->id).'/.lock', 'c');
        self::assertIsResource($other);
        self::assertTrue(flock($other, \LOCK_EX | \LOCK_NB));

        try {
            $this->uploads->complete('blog', $state->id);
            self::fail('Completed while another request held the lock.');
        } catch (Refused $refusal) {
            self::assertSame(UploadService::BUSY, $refusal->getCode());
        } finally {
            flock($other, \LOCK_UN);
            fclose($other);
        }
        $this->uploads->complete('blog', $state->id);
        self::assertSame('abc', file_get_contents($this->assets('busy.sql')));
    }

    // --- abort and abandoned uploads

    public function testAbortRemovesTheStaging(): void
    {
        $state = $this->uploads->begin('blog', '', 'gone.sql', 10);
        $this->uploads->putChunk('blog', $state->id, 0, str_repeat('a', 10));

        $this->uploads->abort('blog', $state->id);

        self::assertSame(0, $this->stagedCount());
        $this->expectException(NotFound::class);
        $this->uploads->status('blog', $state->id);
    }

    public function testAbandonedUploadsAreRemovedByTheNextBeginButActiveOnesStay(): void
    {
        $old = $this->uploads->begin('blog', '', 'old.sql', 10);
        $active = $this->uploads->begin('blog', '', 'active.sql', 10);
        $this->clock->sleep(23 * 3600);
        $this->uploads->putChunk('blog', $active->id, 0, str_repeat('a', 10));
        $this->clock->sleep(2 * 3600);

        $this->uploads->begin('blog', '', 'new.sql', 10);

        self::assertDirectoryDoesNotExist($this->paths->uploadDir($old->id));
        self::assertDirectoryExists($this->paths->uploadDir($active->id), 'touched two hours ago');
        self::assertSame(2, $this->stagedCount());
    }

    public function testAnExpiredUploadCannotBeUsedEvenBeforeItIsCleaned(): void
    {
        $state = $this->uploads->begin('blog', '', 'late.sql', 10);
        $this->clock->sleep(25 * 3600);

        try {
            $this->uploads->putChunk('blog', $state->id, 0, str_repeat('a', 10));
            self::fail('Used an expired upload.');
        } catch (NotFound $missing) {
            self::assertStringContainsString('expired', $missing->getMessage());
        }
        self::assertSame(0, $this->stagedCount());
    }

    public function testGcRemovesOldUploadsAndOldTemporaryFilesOnly(): void
    {
        $state = $this->uploads->begin('blog', '', 'abandoned.sql', 100, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, str_repeat('a', 100));
        mkdir($this->assets('sub'), 0o750, true);
        file_put_contents($this->assets('sub/.fleet-upload-dead.tmp'), str_repeat('t', 50));
        touch($this->assets('sub/.fleet-upload-dead.tmp'), $this->clock->now()->getTimestamp() - 2 * 86400);
        file_put_contents($this->assets('.fleet-upload-live.tmp'), 'being assembled now');
        touch($this->assets('.fleet-upload-live.tmp'), $this->clock->now()->getTimestamp() + 24 * 3600);
        file_put_contents($this->assets('.fleet-upload-old.txt'), 'not ours: wrong suffix');
        touch($this->assets('.fleet-upload-old.txt'), $this->clock->now()->getTimestamp() - 5 * 86400);
        file_put_contents($this->assets('keep.sql'), 'real');
        touch($this->assets('keep.sql'), $this->clock->now()->getTimestamp() - 5 * 86400);
        symlink($this->outside.'/secret.txt', $this->assets('.fleet-upload-link.tmp'));

        $this->clock->sleep(25 * 3600);
        $result = $this->uploads->gc();

        self::assertSame(1, $result->uploads);
        self::assertSame(1, $result->temporaryFiles);
        self::assertGreaterThanOrEqual(150, $result->bytes, 'the 100 byte chunk and the 50 byte temporary file at least');
        self::assertFileDoesNotExist($this->assets('sub/.fleet-upload-dead.tmp'));
        self::assertSame(0, $this->stagedCount());
        self::assertFileExists($this->assets('.fleet-upload-live.tmp'), 'touched an hour ago');
        self::assertFileExists($this->assets('keep.sql'));
        self::assertFileExists($this->assets('.fleet-upload-old.txt'));
        self::assertTrue(is_link($this->assets('.fleet-upload-link.tmp')), 'a link is never touched');
        self::assertSame('original', file_get_contents($this->outside.'/secret.txt'));
    }

    public function testGcOnAnEmptyHostIsANoOp(): void
    {
        $result = $this->uploads->gc();

        self::assertSame([0, 0, 0], [$result->uploads, $result->temporaryFiles, $result->bytes]);
    }

    // --- modes and symbolic links

    public function testTheStagingIsOwnerOnly(): void
    {
        $state = $this->uploads->begin('blog', '', 'm.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, 'abc');

        clearstatcache();
        self::assertSame(0o700, fileperms($this->paths->uploadsDir()) & 0o777);
        self::assertSame(0o700, fileperms($this->paths->uploadDir($state->id)) & 0o777);
        self::assertSame(0o600, fileperms($this->paths->uploadDir($state->id).'/meta.json') & 0o777);
    }

    public function testBeginRefusesADestinationBehindASymlink(): void
    {
        mkdir($this->assets(), 0o750, true);
        symlink($this->outside, $this->assets('link'));

        try {
            $this->uploads->begin('blog', 'link', 'x.sql', 3);
            self::fail('Planned an upload through a symbolic link.');
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Refused::class);
        $this->uploads->begin('blog', '', 'link', 3, null, '', true);
    }

    public function testAFolderSwappedForASymlinkAfterBeginIsNotWrittenThrough(): void
    {
        $state = $this->uploads->begin('blog', 'sub', 'swap.sql', 3, self::CHUNK);
        $this->uploads->putChunk('blog', $state->id, 0, 'abc');
        rmdir($this->assets('sub'));
        symlink($this->outside, $this->assets('sub'));

        try {
            $this->uploads->complete('blog', $state->id);
            self::fail('Wrote through a symbolic link that replaced the folder.');
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(['.', '..', 'secret.txt'], scandir($this->outside));
        self::assertSame('original', file_get_contents($this->outside.'/secret.txt'));
    }

    public function testATargetSwappedForASymlinkAfterBeginIsReplacedNotFollowed(): void
    {
        $state = $this->uploads->begin('blog', '', 'swap2.sql', 3, self::CHUNK, '', true);
        $this->uploads->putChunk('blog', $state->id, 0, 'abc');
        symlink($this->outside.'/secret.txt', $this->assets('swap2.sql'));

        try {
            $this->uploads->complete('blog', $state->id);
            self::fail('Wrote through a symbolic link at the target.');
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('original', file_get_contents($this->outside.'/secret.txt'));
    }
}
