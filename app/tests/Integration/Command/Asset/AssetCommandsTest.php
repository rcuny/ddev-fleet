<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Asset;

use App\Fleet\Assets\AssetStore;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * `fleet asset:list` and `fleet asset:gc`: the tables, the JSON documents and the exit codes (0 done, 1 a value was
 * refused, 2 not allowed, 3 not found) against a real migrated database and FLEET_HOME.
 */
final class AssetCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private string $dir;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $this->dir = self::getContainer()->get(FleetPaths::class)->assetsDir('blog');
        mkdir($this->dir.'/dumps', 0o750, true);
        file_put_contents($this->dir.'/dumps/db.sql.gz', str_repeat('a', 2048));
        file_put_contents($this->dir.'/.env', 'X=1');
        touch($this->dir.'/dumps/db.sql.gz', 1_700_000_000);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testListPrintsATableOfTheTopFolder(): void
    {
        $tester = $this->cli('fleet:asset:list', ['project' => 'blog']);

        self::assertSame(0, $tester->getStatusCode());
        $out = $this->squash($tester->getDisplay());
        self::assertStringContainsString('TYPE NAME SIZE MODIFIED (UTC)', $out);
        self::assertStringContainsString('dir dumps - ', $out);
        self::assertStringContainsString('file .env 3 B', $out);
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testListAFolderAsJson(): void
    {
        $tester = $this->cli('fleet:asset:list', ['project' => 'blog', 'path' => 'dumps', '--format' => 'json']);

        self::assertSame(0, $tester->getStatusCode());
        $document = $this->json($tester);
        self::assertSame('blog', $document['project']);
        self::assertSame('dumps', $document['path']);
        self::assertFalse($document['truncated']);
        self::assertSame([[
            'name' => 'db.sql.gz',
            'path' => 'dumps/db.sql.gz',
            'type' => 'file',
            'size' => 2048,
            'modified' => '2023-11-14T22:13:20Z',
            'usable' => true,
        ]], $document['entries']);
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testJsonFlagIsTheSameAsFormatJson(): void
    {
        $a = $this->cli('fleet:asset:list', ['project' => 'blog', '--json' => true]);
        $b = $this->cli('fleet:asset:list', ['project' => 'blog', '--format' => 'json']);

        self::assertSame($b->getDisplay(), $a->getDisplay());
        self::assertSame('dumps', $this->json($a)['entries'][0]['name']);
    }

    public function testAnEmptyProjectListsNothing(): void
    {
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));

        $tester = $this->cli('fleet:asset:list', ['project' => 'shop', '--json' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->json($tester)['entries']);
    }

    public function testAnUnknownProjectIsExit3(): void
    {
        $tester = $this->cli('fleet:asset:list', ['project' => 'nope']);
        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());

        $json = $this->cli('fleet:asset:list', ['project' => 'nope', '--json' => true]);
        self::assertSame(3, $json->getStatusCode());
        self::assertArrayHasKey('error', $this->json($json));
        self::assertSame('', $json->getErrorOutput());
    }

    public function testAMissingFolderIsExit3(): void
    {
        self::assertSame(3, $this->cli('fleet:asset:list', ['project' => 'blog', 'path' => 'nope'])->getStatusCode());
    }

    public function testATraversalOrBadPathIsExit1NamingThePathArgument(): void
    {
        foreach (['../..', '/etc', 'a//b', "x\0y", 'a:b'] as $path) {
            $tester = $this->cli('fleet:asset:list', ['project' => 'blog', 'path' => $path]);

            self::assertSame(1, $tester->getStatusCode(), json_encode($path) ?: '');
            self::assertStringStartsWith('<path>: ', $tester->getErrorOutput());
        }

        $json = $this->cli('fleet:asset:list', ['project' => 'blog', 'path' => '../..', '--json' => true]);
        self::assertSame(1, $json->getStatusCode());
        $document = $this->json($json);
        self::assertSame('Invalid values.', $document['error']);
        self::assertArrayHasKey('<path>', $document['fields']);
    }

    public function testASymlinkOnTheWayIsExit2(): void
    {
        symlink('/tmp', $this->dir.'/out');

        $tester = $this->cli('fleet:asset:list', ['project' => 'blog', 'path' => 'out']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('symbolic link', $tester->getErrorOutput());
    }

    public function testASymlinkAndAnOddNameAreShownNotFollowed(): void
    {
        symlink('/etc/hostname', $this->dir.'/link');
        file_put_contents($this->dir.'/odd:name', 'x');

        $out = $this->squash($this->cli('fleet:asset:list', ['project' => 'blog'])->getDisplay());

        self::assertStringContainsString("link link -> /etc/hostname (symbolic link, not seeded: points outside the project's assets) -", $out);
        self::assertStringContainsString('odd:name (not addressable)', $out);
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->cli('fleet:asset:list', ['project' => 'blog', '--format' => 'xml']);
    }

    public function testGcOnACleanHost(): void
    {
        $tester = $this->cli('fleet:asset:gc');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed 0 abandoned upload(s) and 0 temporary file(s)', $this->squash($tester->getDisplay()));

        $json = $this->cli('fleet:asset:gc', ['--json' => true]);
        self::assertSame(['uploads' => 0, 'temporaryFiles' => 0, 'bytes' => 0], $this->json($json));
    }

    public function testGcRemovesAnAbandonedUploadAndAStaleTemporaryFile(): void
    {
        $uploads = self::getContainer()->get(FleetPaths::class)->uploadsDir();
        $old = $uploads.'/'.str_repeat('a', 32);
        $fresh = $uploads.'/'.str_repeat('b', 32);
        mkdir($old, 0o700, true);
        mkdir($fresh, 0o700);
        file_put_contents($old.'/0.part', str_repeat('x', 500));
        file_put_contents($old.'/meta.json', '{}');
        file_put_contents($fresh.'/meta.json', '{}');
        touch($old.'/meta.json', time() - 2 * 86400);
        file_put_contents($this->dir.'/dumps/.fleet-upload-dead.tmp', str_repeat('y', 100));
        touch($this->dir.'/dumps/.fleet-upload-dead.tmp', time() - 3 * 86400);

        $tester = $this->cli('fleet:asset:gc', ['--json' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $document = $this->json($tester);
        self::assertSame(1, $document['uploads']);
        self::assertSame(1, $document['temporaryFiles']);
        self::assertSame(602, $document['bytes']);
        self::assertDirectoryDoesNotExist($old);
        self::assertDirectoryExists($fresh);
        self::assertFileDoesNotExist($this->dir.'/dumps/.fleet-upload-dead.tmp');
        self::assertFileExists($this->dir.'/dumps/db.sql.gz');
    }

    public function testTheStoreTypeIsWiredIntoTheCommand(): void
    {
        self::assertInstanceOf(AssetStore::class, self::getContainer()->get(AssetStore::class));
    }
}
