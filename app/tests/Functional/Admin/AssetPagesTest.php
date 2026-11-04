<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Assets tab and its endpoints through HTTP: access per role, the listing (a live read of the filesystem), download,
 * delete, new folder, CSRF, and the chunked upload API end to end.
 */
final class AssetPagesTest extends AdminTestCase
{
    private const int CHUNK = 65_536;

    private string $outside = '';

    protected function tearDown(): void
    {
        if ('' !== $this->outside) {
            foreach (glob($this->outside.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outside);
        }
        parent::tearDown();
    }

    private function makeProject(string $name = 'blog'): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    private function dir(string $relative = ''): string
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        return $paths->assetsDir('blog').('' === $relative ? '' : '/'.$relative);
    }

    private function put(string $relative, string $content = 'x'): void
    {
        if (!is_dir(\dirname($this->dir($relative)))) {
            mkdir(\dirname($this->dir($relative)), 0o750, true);
        }
        file_put_contents($this->dir($relative), $content);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, string $body = '', array $extra = [], string $type = 'application/json'): array
    {
        $server = $this->browserServer() + ['CONTENT_TYPE' => $type, 'HTTP_X_CSRF_TOKEN' => 'csrf-token'];
        $this->client->request($method, self::BASE.$path, [], [], $extra + $server, $body);
        $decoded = json_decode($this->body(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    // --- the tab

    public function testTheTabIsInTheProjectSections(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');

        $link = $crawler->filter('nav[aria-label="Project sections"] a')->reduce(static fn ($a): bool => 'Assets' === trim($a->text()));
        self::assertSame('/admin/projects/blog/assets', $link->attr('href'));
    }

    public function testEmptyProjectShowsTheEmptyStateTheUploadAndTheCap(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog/assets');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=assets-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=asset-row]'));
        $upload = $crawler->filter('[data-controller=asset-upload]');
        self::assertCount(1, $upload);
        self::assertSame('/admin/projects/blog/assets/uploads', $upload->attr('data-asset-upload-url-value'));
        self::assertSame('8388608', $upload->attr('data-asset-upload-chunk-size-value'));
        self::assertSame('2147483648', $upload->attr('data-asset-upload-max-bytes-value'));
        self::assertSame('csrf-token', $upload->attr('data-asset-upload-csrf-value'));
        self::assertStringContainsString('2.0 GiB', $crawler->filter('[data-testid=asset-upload]')->text());
        self::assertStringContainsString('/assets/blog/', $crawler->filter('[data-testid=asset-upload]')->text(), 'the SSH path is shown');
        self::assertNotNull($crawler->filter('[data-testid=asset-input]')->attr('multiple'));
        self::assertDirectoryDoesNotExist($this->dir(), 'looking creates nothing');
    }

    public function testListingShowsNestedFoldersNamesSizesAndFollowsLinks(): void
    {
        $this->makeProject();
        $this->put('db.sql.gz', str_repeat('a', 1536));
        $this->put('dumps/2026/old.sql', 'x');

        $crawler = $this->open('/admin/projects/blog/assets');
        $rows = $crawler->filter('[data-testid=asset-row]');
        self::assertCount(2, $rows);
        self::assertSame('dir', $rows->eq(0)->attr('data-kind'));
        self::assertSame('dumps', trim($rows->eq(0)->filter('[data-testid=asset-name]')->text()));
        self::assertSame('/admin/projects/blog/assets?path=dumps', $rows->eq(0)->filter('a[data-testid=asset-name]')->attr('href'));
        self::assertSame('db.sql.gz', trim($rows->eq(1)->filter('[data-testid=asset-name]')->text()));
        self::assertSame('1.5 KiB', trim($rows->eq(1)->filter('[data-testid=asset-size]')->text()));
        self::assertStringContainsString(
            '/admin/projects/blog/assets/download?path=db.sql.gz',
            (string) $rows->eq(1)->filter('[data-testid=asset-download]')->attr('href'),
        );
        self::assertSame('false', $rows->eq(1)->filter('[data-testid=asset-download]')->attr('data-turbo'));
        self::assertCount(0, $rows->eq(0)->filter('[data-testid=asset-download]'), 'a folder has no download');

        $crawler = $this->open('/admin/projects/blog/assets?path=dumps/2026');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('old.sql', trim($crawler->filter('[data-testid=asset-name]')->text()));
        $crumbs = $crawler->filter('[data-testid=asset-crumbs]');
        self::assertStringContainsString('dumps', $crumbs->text());
        self::assertStringContainsString('2026', $crumbs->text());
        self::assertSame('/admin/projects/blog/assets?path=dumps', $crumbs->filter('a')->eq(1)->attr('href'));
        self::assertSame('dumps/2026', $crawler->filter('[data-controller=asset-upload]')->attr('data-asset-upload-path-value'));
    }

    public function testRescanShowsAFileCopiedInOverSsh(): void
    {
        $this->makeProject();
        $this->put('first.sql');
        $crawler = $this->open('/admin/projects/blog/assets');
        self::assertCount(1, $crawler->filter('[data-testid=asset-row]'));
        $rescan = $crawler->filter('[data-testid=asset-rescan]');
        self::assertSame('/admin/projects/blog/assets', $rescan->attr('href'));

        $this->put('copied-with-scp.sql.gz');
        $crawler = $this->client->request('GET', self::BASE.$rescan->attr('href'), server: $this->browserServer());

        self::assertCount(2, $crawler->filter('[data-testid=asset-row]'));
        self::assertStringContainsString('copied-with-scp.sql.gz', $crawler->filter('[data-testid=asset-listing]')->text());
    }

    public function testNamesAreEscapedAndOddOnesAreMarked(): void
    {
        $this->makeProject();
        $this->put('<script>alert(1)</script>.sql');
        $this->put('weird:name');
        mkdir($this->dir('bad-links'), 0o750, true);
        symlink('/etc/hostname', $this->dir('hostname-link'));

        $this->open('/admin/projects/blog/assets');

        self::assertResponseStatusCodeSame(200);
        $html = $this->body();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        // The first name is refused by the rules, so it is listed but cannot be addressed.
        self::assertStringContainsString('name not supported here', $html);
        self::assertStringContainsString('symbolic link, not seeded: points outside', $html);
    }

    public function testALinkInsideTheAssetsIsMarkedAsSeededOnDeploy(): void
    {
        $this->makeProject();
        $this->put('dumps/dump-prod.sql', 'x');
        symlink('dump-prod.sql', $this->dir('dumps/default.sql'));

        $crawler = $this->open('/admin/projects/blog/assets?path=dumps');

        self::assertResponseStatusCodeSame(200);
        $states = $crawler->filter('[data-testid=asset-link-state]');
        self::assertCount(1, $states);
        self::assertSame('symbolic link, seeded on deploy', trim($states->text()));
        self::assertStringNotContainsString('not seeded', $this->body());
    }

    public function testMissingFolderAndTraversalPathsAre404(): void
    {
        $this->makeProject();
        $this->put('file.txt');

        foreach (['nope', 'file.txt', '..', '../..', '/etc', 'a//b', "x\0y"] as $path) {
            $this->client->request('GET', self::BASE.'/admin/projects/blog/assets', ['path' => $path], [], $this->browserServer());
            self::assertResponseStatusCodeSame(404, json_encode($path) ?: '');
        }
    }

    public function testASymlinkedFolderShowsAProblemNotTheTarget(): void
    {
        $this->makeProject();
        $this->outside = sys_get_temp_dir().'/fleet-outside-'.bin2hex(random_bytes(5));
        mkdir($this->outside);
        file_put_contents($this->outside.'/leaked.txt', 'x');
        mkdir($this->dir(), 0o750, true);
        symlink($this->outside, $this->dir('out'));

        $crawler = $this->open('/admin/projects/blog/assets?path=out');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('symbolic link', $crawler->filter('[data-testid=assets-problem]')->text());
        self::assertStringNotContainsString('leaked.txt', $this->body());
    }

    public function testUnknownProjectIs404(): void
    {
        $this->open('/admin/projects/nope/assets');

        self::assertResponseStatusCodeSame(404);
    }

    // --- download

    public function testDownloadStreamsTheFileAsAnAttachment(): void
    {
        $this->makeProject();
        $this->put('dumps/db one.sql', "SELECT 'é';\n");

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', ['path' => 'dumps/db one.sql'], [], $this->browserServer());

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment; filename="db one.sql"', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame((string) \strlen("SELECT 'é';\n"), $response->headers->get('Content-Length'));
        self::assertSame("SELECT 'é';\n", $this->client->getInternalResponse()->getContent());
    }

    public function testDownloadOfAnUnicodeNameCarriesAnAsciiFallback(): void
    {
        $this->makeProject();
        $this->put('données.sql', 'x');

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', ['path' => 'données.sql'], [], $this->browserServer());

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('filename=donn__es.sql', $disposition);
        self::assertStringContainsString("filename*=utf-8''donn%C3%A9es.sql", $disposition);
    }

    #[DataProvider('badDownloads')]
    public function testDownloadRefusesWhatIsNotAPlainFileInsideTheProject(string $path, int $status): void
    {
        $this->makeProject();
        $this->put('real.txt');
        mkdir($this->dir('folder'), 0o750, true);
        symlink('/etc/hostname', $this->dir('link'));

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', ['path' => $path], [], $this->browserServer());

        self::assertResponseStatusCodeSame($status);
        self::assertStringNotContainsString(trim((string) file_get_contents('/etc/hostname')), (string) $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badDownloads(): iterable
    {
        yield 'missing' => ['nope.sql', 404];
        yield 'traversal' => ['../../../etc/passwd', 404];
        yield 'absolute' => ['/etc/passwd', 404];
        yield 'NUL' => ["real.txt\0", 404];
        yield 'empty' => ['', 404];
        yield 'a folder' => ['folder', 409];
        yield 'a symlink' => ['link', 409];
    }

    // --- delete

    public function testDeleteRemovesAFileAfterTheConfirmation(): void
    {
        $this->makeProject();
        $this->put('dumps/a.sql');
        $this->put('dumps/b.sql');
        $crawler = $this->open('/admin/projects/blog/assets?path=dumps');
        $form = $crawler->filter('form[action$="/assets/delete"]')->eq(0);
        self::assertSame('dumps/a.sql', $form->filter('input[name=path]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('dialog')->count(), 'the confirmation is a dialog');

        $this->post('/admin/projects/blog/assets/delete', ['path' => 'dumps/a.sql']);

        self::assertResponseRedirects('/admin/projects/blog/assets?path=dumps', 303);
        self::assertFileDoesNotExist($this->dir('dumps/a.sql'));
        self::assertFileExists($this->dir('dumps/b.sql'));
        self::assertStringContainsString('Deleted', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
    }

    public function testDeleteNeedsAValidSameOriginRequest(): void
    {
        $this->makeProject();
        $this->put('keep.sql');

        $this->post('/admin/projects/blog/assets/delete', ['path' => 'keep.sql'], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.'/admin/projects/blog/assets/delete', ['path' => 'keep.sql'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.'/admin/projects/blog/assets/delete', ['path' => 'keep.sql', '_token' => 'x'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        self::assertFileExists($this->dir('keep.sql'));
    }

    public function testDeleteIsPostOnly(): void
    {
        $this->makeProject();
        $this->put('keep.sql');

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/delete', ['path' => 'keep.sql'], [], $this->browserServer());

        self::assertResponseStatusCodeSame(404);
        self::assertFileExists($this->dir('keep.sql'));
    }

    public function testDeleteRefusesANonEmptyFolderATraversalAndAMissingFileWithAFlash(): void
    {
        $this->makeProject();
        $this->put('full/a.sql');
        file_put_contents(\dirname($this->dir()).'/../outside-marker', 'x');

        foreach (['full', '../../outside-marker', 'missing.sql', ''] as $path) {
            $this->post('/admin/projects/blog/assets/delete', ['path' => $path]);
            self::assertResponseRedirects(null, 303);
            $flash = $this->client->followRedirect()->filter('[data-testid=flashes]')->text();
            self::assertResponseStatusCodeSame(200);
            self::assertStringContainsString('Nothing was deleted', $flash);
        }
        self::assertFileExists($this->dir('full/a.sql'));
        self::assertFileExists(\dirname($this->dir()).'/../outside-marker');
        @unlink(\dirname($this->dir()).'/../outside-marker');
    }

    // --- new folder

    public function testNewFolderIsCreatedInsideTheCurrentFolder(): void
    {
        $this->makeProject();
        $this->put('dumps/a.sql');

        $this->post('/admin/projects/blog/assets/folders', ['path' => 'dumps', 'name' => '2026']);

        self::assertResponseRedirects('/admin/projects/blog/assets?path=dumps', 303);
        self::assertDirectoryExists($this->dir('dumps/2026'));
        self::assertStringContainsString('Folder created', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
    }

    public function testNewFolderRefusesBadNamesDuplicatesAndCrossSite(): void
    {
        $this->makeProject();
        $this->put('exists/a.sql');

        foreach (['..', 'a/b', '', 'exists', "x\0y"] as $name) {
            $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => $name]);
            self::assertResponseRedirects('/admin/projects/blog/assets', 303);
            self::assertStringContainsString('not created', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        }
        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => 'sneaky'], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);
        self::assertDirectoryDoesNotExist($this->dir('sneaky'));
        self::assertSame(['.', '..', 'exists'], scandir($this->dir()));
    }

    // --- the upload API

    public function testAWholeUploadOverHttp(): void
    {
        $this->makeProject();
        $data = random_bytes(self::CHUNK * 2 + 100);

        $begin = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode([
            'path' => 'dumps', 'name' => 'db.sql.gz', 'size' => \strlen($data), 'chunkSize' => self::CHUNK, 'fingerprint' => 'db:1',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $begin['chunks']);
        self::assertSame(self::CHUNK, $begin['chunkSize']);
        self::assertSame([], $begin['received']);
        $id = (string) $begin['id'];

        foreach ([2, 0, 1] as $index) {
            $state = $this->api('POST', "/admin/projects/blog/assets/uploads/$id/chunks/$index", substr($data, $index * self::CHUNK, self::CHUNK), type: 'application/octet-stream');
            self::assertResponseStatusCodeSame(200);
        }
        self::assertSame([0, 1, 2], $state['received']);
        self::assertSame([0, 1, 2], $this->api('GET', "/admin/projects/blog/assets/uploads/$id")['received']);

        $done = $this->api('POST', "/admin/projects/blog/assets/uploads/$id/complete");
        self::assertResponseStatusCodeSame(200);
        self::assertSame(hash('sha256', $data), $done['sha256']);
        self::assertSame('dumps/db.sql.gz', $done['path']);
        self::assertSame(\strlen($data), $done['size']);
        self::assertSame('128.1 KiB', $done['sizeLabel']);
        self::assertSame($data, file_get_contents($this->dir('dumps/db.sql.gz')));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $crawler = $this->open('/admin/projects/blog/assets?path=dumps');
        self::assertStringContainsString('db.sql.gz', $crawler->filter('[data-testid=asset-listing]')->text());
    }

    public function testBeginAgainResumes(): void
    {
        $this->makeProject();
        $body = json_encode(['path' => '', 'name' => 'r.sql', 'size' => self::CHUNK * 2, 'chunkSize' => self::CHUNK, 'fingerprint' => 'r:1'], \JSON_THROW_ON_ERROR);
        $first = $this->api('POST', '/admin/projects/blog/assets/uploads', $body);
        $this->api('POST', '/admin/projects/blog/assets/uploads/'.$first['id'].'/chunks/1', str_repeat('a', self::CHUNK), type: 'application/octet-stream');

        $again = $this->api('POST', '/admin/projects/blog/assets/uploads', $body);

        self::assertSame($first['id'], $again['id']);
        self::assertSame([1], $again['received']);
    }

    public function testTheCapAnswers413WithTheLimitMessage(): void
    {
        $this->makeProject();
        $hosts = $this->container()->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $hosts);
        $host = new HostSettings('fleet.example.com', new \DateTimeImmutable());
        $host->changeAssetUploadMaxBytes(1_000_000, new \DateTimeImmutable());
        $hosts->save($host);

        $answer = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'big.sql', 'size' => 1_000_001], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('upload limit of 976.6 KiB', $answer['error']);
        self::assertDirectoryDoesNotExist($this->dir(), 'a refused upload creates nothing');
    }

    public function testMistakesAreReportedAsJson(): void
    {
        $this->makeProject();
        $begin = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'e.sql', 'size' => self::CHUNK * 2, 'chunkSize' => self::CHUNK], \JSON_THROW_ON_ERROR));
        $id = (string) $begin['id'];

        $bad = $this->api('POST', "/admin/projects/blog/assets/uploads/$id/chunks/0", 'too short', type: 'application/octet-stream');
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('chunk', $bad['fields']);

        $this->api('POST', "/admin/projects/blog/assets/uploads/$id/chunks/9", str_repeat('a', 10), type: 'application/octet-stream');
        self::assertResponseStatusCodeSame(400);

        $incomplete = $this->api('POST', "/admin/projects/blog/assets/uploads/$id/complete");
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('chunk 0, 1 are missing', $incomplete['error']);

        $this->api('POST', '/admin/projects/blog/assets/uploads/'.str_repeat('0', 32).'/complete');
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/admin/projects/blog/assets/uploads', 'not json');
        self::assertResponseStatusCodeSame(400);
        $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['name' => 'x.sql', 'size' => '12'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '../x', 'name' => 'x.sql', 'size' => 12], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'x.sql', 'size' => 0], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnExistingNameAnswers409UntilOverwriteIsAsked(): void
    {
        $this->makeProject();
        $this->put('db.sql', 'old');
        $request = ['path' => '', 'name' => 'db.sql', 'size' => 3];

        $answer = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode($request, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('exists', $answer['error']);

        $begin = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode($request + ['overwrite' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $this->api('POST', '/admin/projects/blog/assets/uploads/'.$begin['id'].'/chunks/0', 'new', type: 'application/octet-stream');
        $this->api('POST', '/admin/projects/blog/assets/uploads/'.$begin['id'].'/complete');
        self::assertSame('new', file_get_contents($this->dir('db.sql')));
    }

    public function testAbortDiscardsTheUpload(): void
    {
        $this->makeProject();
        $begin = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'a.sql', 'size' => 5], \JSON_THROW_ON_ERROR));

        $this->api('POST', '/admin/projects/blog/assets/uploads/'.$begin['id'].'/abort');
        self::assertResponseStatusCodeSame(200);

        $this->api('GET', '/admin/projects/blog/assets/uploads/'.$begin['id']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheUploadEndpointsNeedTheCsrfHeader(): void
    {
        $this->makeProject();
        $begin = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'c.sql', 'size' => 5], \JSON_THROW_ON_ERROR));
        $id = (string) $begin['id'];
        $none = ['HTTP_X_CSRF_TOKEN' => ''];

        $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'd.sql', 'size' => 5], \JSON_THROW_ON_ERROR), $none);
        self::assertResponseStatusCodeSame(403);
        $this->api('POST', "/admin/projects/blog/assets/uploads/$id/chunks/0", 'abcde', $none, 'application/octet-stream');
        self::assertResponseStatusCodeSame(403);
        $this->api('POST', "/admin/projects/blog/assets/uploads/$id/complete", '', $none);
        self::assertResponseStatusCodeSame(403);
        $this->api('POST', "/admin/projects/blog/assets/uploads/$id/abort", '', $none);
        self::assertResponseStatusCodeSame(403);

        // A cross-site request is refused even with the header.
        $this->client->request('POST', self::BASE."/admin/projects/blog/assets/uploads/$id/chunks/0", [], [], $this->browserServer('cross-site') + ['HTTP_X_CSRF_TOKEN' => 'csrf-token'], 'abcde');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->api('GET', "/admin/projects/blog/assets/uploads/$id")['received']);
    }

    /**
     * Regression (merge-gate browser failure): once a form of the session was accepted by double-submit (cookie + token,
     * what csrf_protection_controller.js does), origin info alone is refused ("double-submit info was used in a previous
     * request but is now missing"). The asset-upload controller must therefore double-submit too.
     */
    public function testUploadEndpointsNeedTheDoubleSubmitOnceTheSessionUsedIt(): void
    {
        $this->makeProject();
        $this->put('keep.sql');
        $token = 'abcdefghijklmnopqrstuvwxyz012345';
        $json = json_encode(['path' => '', 'name' => 'e.sql', 'size' => 5], \JSON_THROW_ON_ERROR);

        // A form post by double-submit (the cookie is set by the page's script, the token travels in the body).
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('__Host-csrf-token_'.$token, 'csrf-token', null, '/', 'localhost', true));
        $this->client->request('POST', self::BASE.'/admin/projects/blog/assets/delete', ['path' => 'keep.sql', '_token' => $token], [], $this->browserServer());
        self::assertResponseRedirects();

        // Origin info alone, the page's bare token: refused now.
        $this->api('POST', '/admin/projects/blog/assets/uploads', $json);
        self::assertResponseStatusCodeSame(403);

        // A fresh random token with its cookie, as the Stimulus controller sends it: accepted.
        $fresh = 'ZYXWVUTSRQPONMLKJIHGFEDCBA987654';
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('__Host-csrf-token_'.$fresh, 'csrf-token', null, '/', 'localhost', true));
        $this->api('POST', '/admin/projects/blog/assets/uploads', $json, ['HTTP_X_CSRF_TOKEN' => $fresh]);
        self::assertResponseIsSuccessful();
    }

    // --- listing, folders, links and uploads: the refusals the browser specs used to check one by one

    public function testTheListingOrdersFoldersFirstThenFilesIgnoringCaseWithSizesAndUtcTimes(): void
    {
        $this->makeProject();
        $crawler = $this->open('/admin/projects/blog/assets');
        self::assertSame('No files yet. Upload one above, or copy files in over SSH and press Rescan.', trim($crawler->filter('[data-testid=assets-empty]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid=assets-summary]'));

        mkdir($this->dir('zeta'), 0o750, true);
        $this->put('b.sql', str_repeat('x', 1536));
        $this->put('A.sql', 'y');
        $this->put('c.sql', str_repeat('z', 3 * 1024 * 1024));
        foreach (['b.sql', 'A.sql', 'c.sql'] as $file) {
            touch($this->dir($file), 1_700_000_000);
        }

        $crawler = $this->open('/admin/projects/blog/assets');
        $names = $crawler->filter('[data-testid=asset-name]')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['zeta', 'A.sql', 'b.sql', 'c.sql'], $names);
        $rows = $crawler->filter('[data-testid=asset-row]');
        self::assertSame('dir', $rows->first()->attr('data-kind'));
        self::assertStringContainsString('4 items, 3.0 MiB in files.', $crawler->filter('[data-testid=assets-summary]')->text());
        self::assertSame('-', trim($rows->first()->filter('[data-testid=asset-size]')->text()));
        self::assertSame('1.5 KiB', trim($rows->eq(2)->filter('[data-testid=asset-size]')->text()));
        self::assertStringContainsString('2023-11-14 22:13', $rows->eq(2)->text());
        self::assertSame(['Name', 'Size', 'Modified (UTC)', 'Actions'], $crawler->filter('th')->each(static fn ($th): string => trim($th->text())));
        self::assertCount(0, $rows->first()->filter('[data-testid=asset-download]'));
        self::assertCount(1, $rows->eq(2)->filter('[data-testid=asset-download]'));

        // One item is singular.
        $this->put('c.sql', 'a');
        @unlink($this->dir('b.sql'));
        @unlink($this->dir('A.sql'));
        rmdir($this->dir('zeta'));
        $crawler = $this->open('/admin/projects/blog/assets');
        self::assertStringContainsString('1 item, 1 B in files.', $crawler->filter('[data-testid=assets-summary]')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badFolderNames(): iterable
    {
        $dots = 'The name must not contain "." or ".." parts.';
        $names = 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.';
        yield 'dotdot' => ['..', $dots];
        yield 'dot' => ['.', $dots];
        yield 'slash' => ['sub/dir', $names];
        yield 'backslash' => ['back\\slash', $names];
        yield 'semicolon' => ['bad;name', $names];
        yield 'trailing space' => ['trailing ', $names];
        yield 'leading space' => [' leading', $names];
        yield 'leading dash' => ['-dash-first', $names];
        yield 'reserved' => ['.fleet-upload-abc', 'That name is reserved.'];
        yield '260 bytes' => [str_repeat('é', 130), 'A name is longer than 255 bytes.'];
        yield 'exists' => ['taken.sql', 'A file or folder with that name exists already.'];
    }

    #[DataProvider('badFolderNames')]
    public function testNewFolderNamesAreRefusedWithTheirReason(string $name, string $reason): void
    {
        $this->makeProject();
        $this->put('taken.sql');

        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => $name]);

        self::assertResponseRedirects('/admin/projects/blog/assets', 303);
        self::assertStringContainsString('The folder was not created. '.$reason, $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame(['.', '..', 'taken.sql'], scandir($this->dir()));
    }

    public function testAFolderThatExistsTwiceIsCreatedOnce(): void
    {
        $this->makeProject();

        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => 'dup']);
        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => 'dup']);

        self::assertStringContainsString('The folder was not created. A file or folder with that name exists already.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame(['.', '..', 'dup'], scandir($this->dir()));
    }

    public function testNewFolderAllowsPunctuationALeadingDotAndAccents(): void
    {
        $this->makeProject();

        foreach (['données-é', 'Mix_ed (1)+a@b,c=d~e-f.g', '.config'] as $good) {
            $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => $good]);
            self::assertStringContainsString('Folder created.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        }

        $found = scandir($this->dir()) ?: [];
        sort($found);
        self::assertSame(['.', '..', '.config', 'Mix_ed (1)+a@b,c=d~e-f.g', 'données-é'], $found);
    }

    public function testTheEighthFolderLevelIsTheLastAndTheNinthIsRefused(): void
    {
        $this->makeProject();
        $levels = array_map(static fn (int $i): string => 'level'.$i.'-'.str_repeat('n', 24), range(1, 8));
        $deepest = implode('/', $levels);
        $this->put($deepest.'/child.sql');

        $crawler = $this->open('/admin/projects/blog/assets?path='.rawurlencode($deepest));
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString($levels[7], $crawler->filter('[data-testid=asset-crumbs]')->text());
        $rows = $crawler->filter('[data-testid=asset-row]');
        self::assertCount(1, $rows);
        self::assertStringContainsString('name not supported here', $rows->text());
        self::assertCount(0, $crawler->filter('[data-testid=asset-delete]'));

        $this->post('/admin/projects/blog/assets/folders', ['path' => $deepest, 'name' => 'ninth']);
        self::assertResponseRedirects('/admin/projects/blog/assets?path='.$deepest, 303);
        self::assertStringContainsString('The folder was not created. The path is more than 8 folders deep.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertDirectoryDoesNotExist($this->dir($deepest.'/ninth'));

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets', ['path' => $deepest.'/ninth'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(404);

        // A folder inside a folder: breadcrumbs name the parents, and an empty folder says so.
        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => 'alpha']);
        $this->post('/admin/projects/blog/assets/folders', ['path' => 'alpha', 'name' => 'beta']);
        $crawler = $this->open('/admin/projects/blog/assets?path=alpha/beta');
        self::assertSame('This folder is empty.', trim($crawler->filter('[data-testid=assets-empty]')->text()));
        self::assertSame(['Assets', 'alpha'], $crawler->filter('[data-testid=asset-crumbs] a')->each(static fn ($a): string => trim($a->text())));
        self::assertSame('beta', trim($crawler->filter('[data-testid=asset-crumbs] [aria-current=page]')->text()));
        self::assertCount(1, $crawler->selectButton('Create folder'));
    }

    public function testMorePathsThatAreNotAFolderAre404(): void
    {
        $this->makeProject();

        foreach (['a/../b', 'bad;name', str_repeat('a/', 600).'z'] as $path) {
            $this->client->request('GET', self::BASE.'/admin/projects/blog/assets', ['path' => $path], [], $this->browserServer());
            self::assertResponseStatusCodeSame(404, substr($path, 0, 20));
        }
    }

    public function testSymbolicLinksAreListedButNeverFollowedDownloadedEnteredOrWrittenThrough(): void
    {
        $this->makeProject();
        $this->put('real/inside.sql');
        $this->put('plain.sql');
        symlink('real', $this->dir('link'));
        symlink('/etc', $this->dir('outside'));
        symlink('nowhere', $this->dir('dangling'));

        $crawler = $this->open('/admin/projects/blog/assets');
        $links = $crawler->filter('[data-testid=asset-row]')->reduce(static fn ($r): bool => $r->filter('[data-testid=asset-link-state]')->count() > 0);
        self::assertCount(3, $links);
        foreach ($links->filter('[data-testid=asset-link-state]') as $state) {
            self::assertMatchesRegularExpression('/^symbolic link, (seeded on deploy|not seeded: .+)$/', trim($state->textContent));
        }
        $outside = $links->reduce(static fn ($r): bool => str_contains($r->text(), 'outside'));
        self::assertSame('→ /etc', trim($outside->filter('[data-testid=asset-link-target]')->text()));
        self::assertCount(0, $links->filter('[data-testid=asset-delete]'));
        self::assertCount(0, $links->filter('[data-testid=asset-download]'));
        self::assertCount(0, $links->filter('a'));
        self::assertCount(5, $crawler->filter('[data-testid=asset-row]'));

        $crawler = $this->open('/admin/projects/blog/assets?path=link');
        self::assertStringContainsString('A symbolic link is in the way. The browser never follows symbolic links.', $crawler->filter('[data-testid=assets-problem]')->text());
        self::assertCount(5, $crawler->filter('[data-testid=asset-row]'));

        foreach (['link/inside.sql', 'outside/passwd', 'dangling'] as $path) {
            $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', ['path' => $path], [], $this->browserServer());
            self::assertResponseStatusCodeSame(409, $path);
        }

        // The folder turns into a link: creating a folder or uploading in it is refused.
        mkdir($this->dir('moved'));
        unlink($this->dir('link'));
        $this->put('gone/x');
        $this->rrmdir($this->dir('gone'));
        $this->rrmdir($this->dir('real'));
        symlink('moved', $this->dir('real'));

        $answer = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => 'real', 'name' => 'through.sql', 'size' => 5], \JSON_THROW_ON_ERROR));
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('A symbolic link is in the way. The browser never follows symbolic links.', (string) $answer['error']);

        $this->post('/admin/projects/blog/assets/folders', ['path' => 'real', 'name' => 'child']);
        self::assertStringContainsString('The folder was not created. A symbolic link is in the way.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame(['.', '..'], scandir($this->dir('moved')));
    }

    public function testAProjectAssetsDirectoryThatIsASymbolicLinkShowsAMessageAndAnEmptyListing(): void
    {
        $this->makeProject();
        $this->outside = sys_get_temp_dir().'/fleet-outside-'.bin2hex(random_bytes(5));
        mkdir($this->outside);
        file_put_contents($this->outside.'/hidden.sql', 'x');
        mkdir(\dirname($this->dir()), 0o750, true);
        symlink($this->outside, $this->dir());

        $crawler = $this->open('/admin/projects/blog/assets');

        self::assertStringContainsString("The project's assets directory is a symbolic link. The browser never follows symbolic links.", $crawler->filter('[data-testid=assets-problem]')->text());
        self::assertCount(1, $crawler->filter('[data-testid=assets-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=asset-row]'));

        $this->post('/admin/projects/blog/assets/folders', ['path' => '', 'name' => 'child']);
        self::assertStringContainsString('The folder was not created. A symbolic link is in the way.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame(['.', '..', 'hidden.sql'], scandir($this->outside));
        unlink($this->dir());
    }

    private function rrmdir(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $child) {
            is_dir($child) ? $this->rrmdir($child) : unlink($child);
        }
        @rmdir($path);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function beginRefusals(): iterable
    {
        $valid = ['path' => '', 'name' => 'api.bin', 'size' => 70_000, 'chunkSize' => self::CHUNK, 'fingerprint' => 'fp-1'];
        yield 'null body' => ['null', 400, 'The request body must be a JSON object.'];
        yield 'size as text' => [json_encode(['name' => 'a.bin', 'size' => 'big'], \JSON_THROW_ON_ERROR), 400, 'Send path, name, size and fingerprint as text and whole numbers.'];
        yield 'empty file' => [json_encode(['size' => 0] + $valid, \JSON_THROW_ON_ERROR), 400, 'The file is empty.'];
        yield 'long fingerprint' => [json_encode(['fingerprint' => str_repeat('f', 513)] + $valid, \JSON_THROW_ON_ERROR), 400, 'The fingerprint is longer than 512 bytes.'];
        yield 'absolute path' => [json_encode(['path' => '/etc'] + $valid, \JSON_THROW_ON_ERROR), 400, "The path must be relative to the project's assets directory."];
        yield 'dotdot in path' => [json_encode(['path' => 'a/../b'] + $valid, \JSON_THROW_ON_ERROR), 400, 'The path must not contain "." or ".." parts.'];
        yield 'empty part' => [json_encode(['path' => 'a//b'] + $valid, \JSON_THROW_ON_ERROR), 400, 'The path is empty or has an empty part.'];
        yield 'NUL in path' => [json_encode(['path' => "a\0b"] + $valid, \JSON_THROW_ON_ERROR), 400, 'The path contains a NUL character.'];
        yield 'nine deep' => [json_encode(['path' => str_repeat('a/', 9).'z'] + $valid, \JSON_THROW_ON_ERROR), 400, 'The path is more than 8 folders deep.'];
        yield 'long path' => [json_encode(['path' => str_repeat('a/', 600).'z'] + $valid, \JSON_THROW_ON_ERROR), 400, 'The path is longer than 1024 bytes.'];
        yield 'slash in name' => [json_encode(['name' => 'a/b'] + $valid, \JSON_THROW_ON_ERROR), 400, 'Names may use letters'];
        yield 'dotdot name' => [json_encode(['name' => '..'] + $valid, \JSON_THROW_ON_ERROR), 400, 'The name must not contain "." or ".." parts.'];
        yield 'long name' => [json_encode(['name' => str_repeat('x', 256)] + $valid, \JSON_THROW_ON_ERROR), 400, 'A name is longer than 255 bytes.'];
        yield 'reserved name' => [json_encode(['name' => '.fleet-upload-x'] + $valid, \JSON_THROW_ON_ERROR), 400, 'That name is reserved.'];
        yield 'bad characters' => [json_encode(['name' => 'bad;one.sql'] + $valid, \JSON_THROW_ON_ERROR), 400, 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.'];
        yield 'over the cap' => [json_encode(['size' => 3 * 1024 ** 3] + $valid, \JSON_THROW_ON_ERROR), 413, 'more than the upload limit of 2.0 GiB'];
    }

    #[DataProvider('beginRefusals')]
    public function testBeginRefusalsAnswerAStatusAndAMessage(string $body, int $status, string $message): void
    {
        $this->makeProject();

        $answer = $this->api('POST', '/admin/projects/blog/assets/uploads', $body);

        self::assertResponseStatusCodeSame($status);
        self::assertStringContainsString($message, (string) ($answer['error'] ?? ''));
        self::assertDirectoryDoesNotExist($this->dir(), 'a refused upload creates nothing');
    }

    public function testAFolderInTheWayRefusesTheUploadName(): void
    {
        $this->makeProject();
        mkdir($this->dir('occupied'), 0o750, true);

        $answer = $this->api('POST', '/admin/projects/blog/assets/uploads', json_encode(['path' => '', 'name' => 'occupied', 'size' => 1], \JSON_THROW_ON_ERROR));

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('A folder with that name exists.', (string) $answer['error']);
    }

    public function testTheChunkAndCompleteAnswersNameTheirMistake(): void
    {
        $this->makeProject();
        $base = '/admin/projects/blog/assets/uploads';
        $begin = $this->api('POST', $base, json_encode(['path' => '', 'name' => 'api.bin', 'size' => 70_000, 'chunkSize' => self::CHUNK, 'fingerprint' => 'fp-1'], \JSON_THROW_ON_ERROR));
        self::assertSame(2, $begin['chunks']);
        $id = (string) $begin['id'];
        self::assertSame([], $this->api('GET', "$base/$id")['received']);

        $answer = $this->api('POST', "$base/$id/chunks/7", 'x', type: 'application/octet-stream');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('There is no chunk 7: this upload has 2.', $answer['error']);
        $answer = $this->api('POST', "$base/$id/chunks/0", str_repeat('x', 10), type: 'application/octet-stream');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Chunk 0 should be 65536 bytes; 10 bytes arrived.', $answer['error']);
        $answer = $this->api('POST', "$base/$id/complete");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The upload is not complete: chunk 0, 1 are missing.', $answer['error']);
        $answer = $this->api('POST', "$base/$id/chunks/0", str_repeat('x', self::CHUNK), type: 'application/octet-stream');
        self::assertSame([0], $answer['received']);
        $answer = $this->api('POST', "$base/$id/complete");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The upload is not complete: chunk 1 is missing.', $answer['error']);

        // A write needs the CSRF token; reading a status needs none.
        $answer = $this->api('POST', "$base/$id/abort", '', ['HTTP_X_CSRF_TOKEN' => '']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Invalid CSRF token. Reload the page.', $answer['error']);
        $answer = $this->api('GET', "$base/$id", '', ['HTTP_X_CSRF_TOKEN' => '']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([0], $answer['received']);

        $answer = $this->api('POST', "$base/$id/abort");
        self::assertTrue($answer['aborted']);
        $this->api('GET', "$base/$id");
        self::assertResponseStatusCodeSame(404);
        $this->api('POST', "$base/$id/complete");
        self::assertResponseStatusCodeSame(404);
        $this->api('GET', $base.'/'.str_repeat('0', 32));
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], glob($this->dir().'/*') ?: [], 'nothing was published');
    }

    public function testDownloadAnswersWithNoStoreAndAnAccentedNameAndNeedsAPath(): void
    {
        $this->makeProject();
        $this->put('résumé données (1).sql', 'accents');

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', ['path' => 'résumé données (1).sql'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''r%C3%A9sum%C3%A9%20donn%C3%A9es%20%281%29.sql", (string) $response->headers->get('Content-Disposition'));

        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/download', [], [], $this->browserServer());
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRefusalsSayWhy(): void
    {
        $this->makeProject();
        $this->put('full/inside.sql');
        mkdir($this->dir('empty'), 0o750, true);

        $this->post('/admin/projects/blog/assets/delete', ['path' => 'missing.sql']);
        self::assertStringContainsString('Nothing was deleted. That file or folder does not exist.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        $this->post('/admin/projects/blog/assets/delete', ['path' => 'full']);
        self::assertStringContainsString('Nothing was deleted. The folder is not empty. Delete what is in it first.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertFileExists($this->dir('full/inside.sql'));
        $this->post('/admin/projects/blog/assets/delete', ['path' => 'empty']);
        self::assertStringContainsString('Deleted.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertDirectoryDoesNotExist($this->dir('empty'));
    }

    public function testFolderAndDeleteRejectAWrongTokenAndAMissingOne(): void
    {
        $this->makeProject();

        foreach (['/admin/projects/blog/assets/folders' => ['path' => '', 'name' => 'sneaky'], '/admin/projects/blog/assets/delete' => ['path' => 'x']] as $url => $form) {
            $this->client->request('POST', self::BASE.$url, $form + ['_token' => 'wrong'], [], $this->browserServer());
            self::assertResponseStatusCodeSame(403, $url);
            $this->client->request('POST', self::BASE.$url, $form, [], $this->browserServer());
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertDirectoryDoesNotExist($this->dir('sneaky'));
    }

    // --- access

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        $id = str_repeat('a', 32);
        yield 'browser' => ['GET', '/admin/projects/blog/assets'];
        yield 'download' => ['GET', '/admin/projects/blog/assets/download?path=a.sql'];
        yield 'folder' => ['POST', '/admin/projects/blog/assets/folders'];
        yield 'delete' => ['POST', '/admin/projects/blog/assets/delete'];
        yield 'begin' => ['POST', '/admin/projects/blog/assets/uploads'];
        yield 'status' => ['GET', "/admin/projects/blog/assets/uploads/$id"];
        yield 'chunk' => ['POST', "/admin/projects/blog/assets/uploads/$id/chunks/0"];
        yield 'complete' => ['POST', "/admin/projects/blog/assets/uploads/$id/complete"];
        yield 'abort' => ['POST', "/admin/projects/blog/assets/uploads/$id/abort"];
    }

    #[DataProvider('routes')]
    public function testMemberGets403OnEveryRoute(string $method, string $path): void
    {
        $this->makeProject();
        $this->put('a.sql', 'keep');
        $this->as('member');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token', 'path' => 'a.sql', 'name' => 'x'] : [], [], $this->browserServer() + ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('keep', file_get_contents($this->dir('a.sql')));
        self::assertSame(['.', '..', 'a.sql'], scandir($this->dir()));
    }

    #[DataProvider('routes')]
    public function testAnonymousGets401OnEveryRoute(string $method, string $path): void
    {
        $this->makeProject();
        $this->put('a.sql', 'keep');
        $this->as('none');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token', 'path' => 'a.sql', 'name' => 'x'] : [], [], $this->browserServer() + ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['.', '..', 'a.sql'], scandir($this->dir()));
    }

    #[DataProvider('routes')]
    public function testAdminReachesEveryRoute(string $method, string $path): void
    {
        $this->makeProject();
        $this->put('a.sql', 'keep');

        $this->client->request($method, self::BASE.$path, [], [], $this->browserServer() + ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);

        // Past the access rule and the voter: a page, a download, a redirect with a flash, or a JSON answer.
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 303, 400, 404], $method.' '.$path);
    }
}
