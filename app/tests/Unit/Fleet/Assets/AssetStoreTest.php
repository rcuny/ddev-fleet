<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Assets;

use App\Fleet\Assets\AssetEntry;
use App\Fleet\Assets\AssetStore;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use PHPUnit\Framework\TestCase;

/**
 * Confinement to a project's assets directory, on a real filesystem: traversal, symbolic links (never followed, never
 * written through), and the list / folder / delete / open / publish operations.
 */
final class AssetStoreTest extends TestCase
{
    private string $home;
    private string $outside;
    private FleetPaths $paths;
    private AssetStore $store;
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/fleet-assets-'.bin2hex(random_bytes(5));
        $this->home = $base.'/home';
        $this->outside = $base.'/outside';
        mkdir($this->outside, 0o755, true);
        file_put_contents($this->outside.'/secret.txt', 'original');
        mkdir($this->outside.'/dir', 0o755);

        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->paths->ensureLayout();
        $this->root = $this->paths->assetsDir('blog');
        mkdir($this->root, 0o750);
        $this->store = new AssetStore($this->paths);
    }

    protected function tearDown(): void
    {
        self::remove(\dirname($this->home));
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                self::remove($path.'/'.$name);
            }
        }
        @rmdir($path);
    }

    private function secretIsUntouched(): void
    {
        self::assertSame('original', file_get_contents($this->outside.'/secret.txt'));
        self::assertSame(['.', '..', 'dir', 'secret.txt'], scandir($this->outside));
    }

    // --- list

    public function testListsFoldersFirstThenFilesWithSizes(): void
    {
        mkdir($this->root.'/zeta');
        mkdir($this->root.'/Alpha');
        file_put_contents($this->root.'/b.sql', '12345');
        file_put_contents($this->root.'/A.sql', '1');
        file_put_contents($this->root.'/.env', 'X=1');
        touch($this->root.'/b.sql', 1_700_000_000);

        $listing = $this->store->list('blog');

        self::assertSame(['Alpha', 'zeta', '.env', 'A.sql', 'b.sql'], array_map(static fn (AssetEntry $e): string => $e->name, $listing->entries));
        self::assertSame(['dir', 'dir', 'file', 'file', 'file'], array_map(static fn (AssetEntry $e): string => $e->kind, $listing->entries));
        $b = $listing->entries[4];
        self::assertSame(5, $b->size);
        self::assertSame(1_700_000_000, $b->modified);
        self::assertSame('b.sql', $b->path);
        self::assertTrue($b->usable);
        self::assertNull($listing->entries[0]->size);
        self::assertSame(9, $listing->totalBytes());
        self::assertFalse($listing->truncated);
        self::assertNull($listing->parent());
    }

    public function testListsASubfolderWithBreadcrumbs(): void
    {
        mkdir($this->root.'/dumps/2026', 0o750, true);
        file_put_contents($this->root.'/dumps/2026/db.sql', 'x');

        $listing = $this->store->list('blog', 'dumps/2026');

        self::assertSame('dumps/2026', $listing->path());
        self::assertSame('dumps', $listing->parent());
        self::assertSame([['name' => 'dumps', 'path' => 'dumps'], ['name' => '2026', 'path' => 'dumps/2026']], $listing->crumbs());
        self::assertSame('dumps/2026/db.sql', $listing->entries[0]->path);
    }

    public function testAProjectWithoutAnAssetsDirectoryHasAnEmptyListing(): void
    {
        rmdir($this->root);

        self::assertSame([], $this->store->list('blog')->entries);
        $this->expectException(NotFound::class);
        $this->store->list('blog', 'dumps');
    }

    public function testAMissingFolderOrAFileInsteadOfAFolderIsNotFound(): void
    {
        file_put_contents($this->root.'/file.txt', 'x');
        foreach (['nope', 'file.txt', 'file.txt/inside'] as $path) {
            try {
                $this->store->list('blog', $path);
                self::fail($path);
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNeverListsTheUploadTempFilesAndMarksWhatTheBrowserCannotAddress(): void
    {
        file_put_contents($this->root.'/.fleet-upload-abc.tmp', 'half');
        file_put_contents($this->root.'/weird:name.sql', 'x');
        file_put_contents($this->root."/bad\xFFbytes", 'x');
        file_put_contents($this->root."/line\nbreak", 'x');
        symlink($this->outside.'/secret.txt', $this->root.'/link');
        file_put_contents($this->root.'/good.sql', 'x');

        $entries = [];
        foreach ($this->store->list('blog')->entries as $entry) {
            $entries[$entry->name] = $entry;
        }

        self::assertArrayNotHasKey('.fleet-upload-abc.tmp', $entries);
        self::assertTrue($entries['good.sql']->usable);
        self::assertFalse($entries['weird:name.sql']->usable);
        self::assertSame('', $entries['weird:name.sql']->path);
        self::assertSame(AssetEntry::LINK, $entries['link']->kind);
        self::assertFalse($entries['link']->usable);
        self::assertFalse($entries['link']->linkSeeded);
        self::assertSame($this->outside.'/secret.txt', $entries['link']->linkTarget);
        self::assertStringContainsString('outside', $entries['link']->linkNote);
        self::assertNull($entries['link']->size);
        $printable = array_keys($entries);
        foreach ($printable as $name) {
            self::assertTrue(mb_check_encoding($name, 'UTF-8'), 'names are fit to print');
            self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $name));
        }
        self::assertCount(5, $entries);
    }

    public function testASymlinkedFolderIsListedAsALinkAndNeverEntered(): void
    {
        symlink($this->outside, $this->root.'/out');

        self::assertSame(AssetEntry::LINK, $this->store->list('blog')->entries[0]->kind);
        foreach (['out', 'out/dir'] as $path) {
            try {
                $this->store->list('blog', $path);
                self::fail($path);
            } catch (Refused $refusal) {
                self::assertStringContainsString('symbolic link', $refusal->getMessage());
            }
        }
    }

    public function testTheProjectDirectoryItselfMayNotBeASymlink(): void
    {
        rmdir($this->root);
        symlink($this->outside, $this->root);

        foreach ([
            fn () => $this->store->list('blog'),
            fn () => $this->store->open('blog', 'secret.txt'),
            fn () => $this->store->createFolder('blog', '', 'x'),
            fn () => $this->store->prepareTarget('blog', '', 'x.txt', false),
            fn () => $this->store->delete('blog', 'secret.txt'),
        ] as $call) {
            try {
                $call();
                self::fail('A symlinked project directory was used.');
            } catch (Refused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->secretIsUntouched();
    }

    // --- traversal and symlinks

    public function testTraversalAndAbsolutePathsAreRefusedEverywhere(): void
    {
        file_put_contents($this->home.'/keep.txt', 'keep');
        foreach (['../../outside/secret.txt', '..', '/etc/passwd', 'a/../../x', "a\0b"] as $path) {
            foreach ([
                fn () => $this->store->list('blog', $path),
                fn () => $this->store->open('blog', $path),
                fn () => $this->store->delete('blog', $path),
                fn () => $this->store->createFolder('blog', $path, 'x'),
                fn () => $this->store->prepareTarget('blog', $path, 'x.txt', true),
            ] as $call) {
                try {
                    $call();
                    self::fail('Accepted '.json_encode($path, \JSON_INVALID_UTF8_SUBSTITUTE));
                } catch (ValidationFailed) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame('keep', file_get_contents($this->home.'/keep.txt'));
        $this->secretIsUntouched();
    }

    public function testNothingIsReadThroughASymlink(): void
    {
        symlink($this->outside.'/secret.txt', $this->root.'/file-link');
        symlink($this->outside, $this->root.'/dir-link');

        foreach (['file-link', 'dir-link/secret.txt', 'dir-link'] as $path) {
            try {
                $this->store->open('blog', $path);
                self::fail($path);
            } catch (Refused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNothingIsWrittenOrDeletedThroughASymlink(): void
    {
        symlink($this->outside, $this->root.'/dir-link');
        symlink($this->outside.'/secret.txt', $this->root.'/file-link');

        foreach ([
            fn () => $this->store->createFolder('blog', 'dir-link', 'made'),
            fn () => $this->store->prepareTarget('blog', 'dir-link', 'made.txt', true),
            fn () => $this->store->delete('blog', 'dir-link/secret.txt'),
            fn () => $this->store->delete('blog', 'dir-link/dir'),
            fn () => $this->store->prepareTarget('blog', '', 'file-link', true),
        ] as $call) {
            try {
                $call();
                self::fail('Went through a symbolic link.');
            } catch (Refused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->secretIsUntouched();
    }

    public function testPublishReplacesALinkNeverWritesThroughIt(): void
    {
        file_put_contents($this->root.'/.fleet-upload-x.tmp', 'new bytes');
        symlink($this->outside.'/secret.txt', $this->root.'/target');

        // checkTarget() refuses a link at the target even with $overwrite: nothing is moved.
        try {
            $this->store->publish($this->root.'/.fleet-upload-x.tmp', $this->root.'/target', true);
            self::fail('Replaced a symbolic link.');
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
        $this->secretIsUntouched();
        self::assertTrue(is_link($this->root.'/target'));
    }

    public function testADeepSymlinkOnTheWayIsRefusedEvenWhenTheFirstPartsAreReal(): void
    {
        mkdir($this->root.'/real');
        symlink($this->outside, $this->root.'/real/hop');

        foreach (['real/hop', 'real/hop/secret.txt'] as $path) {
            try {
                $this->store->open('blog', $path);
                self::fail($path);
            } catch (Refused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- folders and delete

    public function testCreateFolderNestsAndRefusesDuplicates(): void
    {
        self::assertSame('dumps', $this->store->createFolder('blog', '', 'dumps'));
        self::assertSame('dumps/2026', $this->store->createFolder('blog', 'dumps', '2026'));
        clearstatcache();
        self::assertSame(0o750, fileperms($this->root.'/dumps/2026') & 0o777);

        try {
            $this->store->createFolder('blog', 'dumps', '2026');
            self::fail('Created a folder twice.');
        } catch (Refused $refusal) {
            self::assertSame(409, $refusal->getCode());
        }
        file_put_contents($this->root.'/file', 'x');
        $this->expectException(Refused::class);
        $this->store->createFolder('blog', '', 'file');
    }

    public function testCreateFolderRefusesAnUnsafeNameAndTooMuchDepth(): void
    {
        foreach (['a/b', '..', '', "x\0", 'x:y'] as $name) {
            try {
                $this->store->createFolder('blog', '', $name);
                self::fail((string) json_encode($name));
            } catch (ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
        $deep = implode('/', array_fill(0, 8, 'd'));
        mkdir($this->root.'/'.$deep, 0o750, true);
        $this->expectException(ValidationFailed::class);
        $this->store->createFolder('blog', $deep, 'one-too-many');
    }

    public function testCreateFolderCreatesTheProjectDirectoryWhenMissing(): void
    {
        rmdir($this->root);

        $this->store->createFolder('blog', '', 'first');

        self::assertDirectoryExists($this->root.'/first');
    }

    public function testDeleteRemovesAFileAnEmptyFolderAndALinkItself(): void
    {
        file_put_contents($this->root.'/a.sql', 'x');
        mkdir($this->root.'/empty');
        symlink($this->outside.'/secret.txt', $this->root.'/link');

        $this->store->delete('blog', 'a.sql');
        $this->store->delete('blog', 'empty');
        $this->store->delete('blog', 'link');

        self::assertSame(['.', '..'], scandir($this->root));
        $this->secretIsUntouched();
    }

    public function testDeleteRemovesAFolderHoldingOnlyStaleUploadTemporaries(): void
    {
        mkdir($this->root.'/stale');
        $old = $this->root.'/stale/.fleet-upload-0123.tmp';
        file_put_contents($old, 'x');
        touch($old, time() - AssetStore::STALE_TEMPORARY_AFTER - 60);

        $this->store->delete('blog', 'stale');

        self::assertDirectoryDoesNotExist($this->root.'/stale');
    }

    public function testDeleteKeepsAFolderWithAFreshTemporaryOrAnyOtherHiddenFile(): void
    {
        mkdir($this->root.'/fresh');
        file_put_contents($this->root.'/fresh/.fleet-upload-0123.tmp', 'x');
        mkdir($this->root.'/hidden');
        file_put_contents($this->root.'/hidden/.htaccess', 'x');
        file_put_contents($this->root.'/hidden/.fleet-upload-old.tmp', 'x');
        touch($this->root.'/hidden/.fleet-upload-old.tmp', time() - AssetStore::STALE_TEMPORARY_AFTER - 60);

        foreach (['fresh', 'hidden'] as $folder) {
            try {
                $this->store->delete('blog', $folder);
                self::fail('Deleted '.$folder);
            } catch (Refused $refusal) {
                self::assertSame(409, $refusal->getCode());
            }
        }
        self::assertFileExists($this->root.'/fresh/.fleet-upload-0123.tmp');
        // Nothing is removed from a folder that stays: the stale temporary is still there.
        self::assertFileExists($this->root.'/hidden/.fleet-upload-old.tmp');
    }

    public function testDeleteRefusesANonEmptyFolderTheTopAndAMissingPath(): void
    {
        mkdir($this->root.'/full');
        file_put_contents($this->root.'/full/a', 'x');

        try {
            $this->store->delete('blog', 'full');
            self::fail('Deleted a non-empty folder.');
        } catch (Refused $refusal) {
            self::assertSame(409, $refusal->getCode());
        }
        self::assertFileExists($this->root.'/full/a');

        try {
            $this->store->delete('blog', '');
            self::fail('Deleted the top.');
        } catch (ValidationFailed) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFound::class);
        $this->store->delete('blog', 'missing.sql');
    }

    // --- open

    public function testOpenReturnsAHandleAndTheSize(): void
    {
        mkdir($this->root.'/dumps');
        file_put_contents($this->root.'/dumps/db.sql', 'SELECT 1;');

        $file = $this->store->open('blog', 'dumps/db.sql');

        self::assertSame('db.sql', $file->name);
        self::assertSame(9, $file->size);
        self::assertSame('SELECT 1;', stream_get_contents($file->handle));
        fclose($file->handle);
    }

    public function testOpenRefusesAFolderAndAMissingFile(): void
    {
        mkdir($this->root.'/dir');
        try {
            $this->store->open('blog', 'dir');
            self::fail('Opened a folder.');
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->store->open('blog', '');
            self::fail('Opened the top.');
        } catch (ValidationFailed) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFound::class);
        $this->store->open('blog', 'missing');
    }

    // --- putting a file in place

    public function testPrepareTargetCreatesFoldersAndChecksTheTarget(): void
    {
        [$directory, $target] = $this->store->prepareTarget('blog', 'a/b', 'db.sql', false);

        self::assertSame($this->root.'/a/b', $directory);
        self::assertSame($this->root.'/a/b/db.sql', $target);
        self::assertDirectoryExists($directory);
        clearstatcache();
        self::assertSame(0o750, fileperms($directory) & 0o777);

        file_put_contents($target, 'old');
        try {
            $this->store->prepareTarget('blog', 'a/b', 'db.sql', false);
            self::fail('Allowed a replacement without $overwrite.');
        } catch (Refused $refusal) {
            self::assertSame(409, $refusal->getCode());
        }
        $this->store->prepareTarget('blog', 'a/b', 'db.sql', true);

        mkdir($this->root.'/a/b/folder');
        $this->expectException(Refused::class);
        $this->store->prepareTarget('blog', 'a/b', 'folder', true);
    }

    public function testPublishNeverClobbersWithoutOverwriteAndReplacesWithIt(): void
    {
        file_put_contents($this->root.'/existing.sql', 'old');
        file_put_contents($this->root.'/.fleet-upload-1.tmp', 'new');

        try {
            $this->store->publish($this->root.'/.fleet-upload-1.tmp', $this->root.'/existing.sql', false);
            self::fail('Clobbered a file.');
        } catch (Refused $refusal) {
            self::assertSame(409, $refusal->getCode());
        }
        self::assertSame('old', file_get_contents($this->root.'/existing.sql'));

        $this->store->publish($this->root.'/.fleet-upload-1.tmp', $this->root.'/existing.sql', true);
        self::assertSame('new', file_get_contents($this->root.'/existing.sql'));
        self::assertFileDoesNotExist($this->root.'/.fleet-upload-1.tmp');
        clearstatcache();
        self::assertSame(0o640, fileperms($this->root.'/existing.sql') & 0o777);

        file_put_contents($this->root.'/.fleet-upload-2.tmp', 'fresh');
        $this->store->publish($this->root.'/.fleet-upload-2.tmp', $this->root.'/brand-new.sql', false);
        self::assertSame('fresh', file_get_contents($this->root.'/brand-new.sql'));
        self::assertFileDoesNotExist($this->root.'/.fleet-upload-2.tmp');
    }
}
