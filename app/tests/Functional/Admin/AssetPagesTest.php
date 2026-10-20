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
        $host = new HostSettings('fleet.example.com');
        $host->changeAssetUploadMaxBytes(1_000_000);
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
