<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\ProjectRepository;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The export download on the project page and the Import page (upload, preview, confirm): access, CSRF, what the
 * download carries, and that a refused file writes nothing.
 */
final class ProjectTransferTest extends AdminTestCase
{
    private const string SECRET = 'transfer-secret-value-91';
    private const string PASSPHRASE = 'correct horse battery';

    private function seed(): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('dev', null, ['composer install']));
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        $store->putManaged('blog', 'API_KEY', self::SECRET, 'admin');
    }

    private function projectCount(): int
    {
        $repository = $this->container()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $repository);
        $this->em();

        return \count($repository->all());
    }

    /** The export text of "blog", after which the project is deleted so the file can be imported. */
    private function exportAndRemove(?string $passphrase = null): string
    {
        $this->seed();
        $exporter = $this->container()->get(ProjectExporter::class);
        self::assertInstanceOf(ProjectExporter::class, $exporter);
        $text = $exporter->export('blog', $passphrase, 'admin');
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->delete('blog', 'admin');
        $this->em();

        return $text;
    }

    private function upload(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->post('/admin/import/preview', [], ['file' => new UploadedFile($path, 'blog.fleet-project.json', 'application/json', null, true)]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'form' => ['GET', '/admin/import'];
        yield 'preview' => ['POST', '/admin/import/preview'];
        yield 'apply' => ['POST', '/admin/import/apply'];
        yield 'export' => ['POST', '/admin/projects/blog/export'];
    }

    #[DataProvider('routes')]
    public function testAMemberGets403AndAnonymousGets401(string $method, string $path): void
    {
        $this->seed();

        foreach (['member' => 403, 'none' => 401] as $role => $status) {
            $this->as($role);
            $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token', 'document' => '{}'] : [], [], $this->browserServer());
            self::assertResponseStatusCodeSame($status, $role.' '.$method.' '.$path);
            self::assertStringNotContainsString(self::SECRET, $this->body());
        }
        self::assertSame(1, $this->projectCount());
    }

    public function testPostsRefuseABadCsrfToken(): void
    {
        $this->seed();

        $this->post('/admin/projects/blog/export', [], [], 'cross-site');
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/import/preview', [], [], 'cross-site');
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/import/apply', ['document' => '{}'], [], 'cross-site');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheProjectPageOffersTheExport(): void
    {
        $this->seed();

        $crawler = $this->open('/admin/projects/blog');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=export-project] form[action="/admin/projects/blog/export"]'));
        self::assertCount(1, $crawler->filter('[data-testid=export-passphrase][type=password]'));
    }

    public function testTheExportIsADownloadWithoutSecrets(): void
    {
        $this->seed();

        $this->post('/admin/projects/blog/export', []);

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('blog.fleet-project.json', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $document = json_decode($this->body(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('fleet-project-export', $document['format']);
        self::assertSame('blog', $document['project']['name']);
        self::assertArrayNotHasKey('secrets', $document);
        self::assertStringNotContainsString(self::SECRET, $this->body());
    }

    public function testTheExportWithAPassphraseSealsTheSecrets(): void
    {
        $this->seed();

        $this->post('/admin/projects/blog/export', ['passphrase' => self::PASSPHRASE]);

        self::assertResponseStatusCodeSame(200);
        $document = json_decode($this->body(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertArrayHasKey('secrets', $document);
        self::assertStringNotContainsString(self::SECRET, $this->body());
        self::assertStringNotContainsString(self::PASSPHRASE, $this->body());
    }

    public function testAShortPassphraseIsRefusedWithoutADownload(): void
    {
        $this->seed();

        $this->post('/admin/projects/blog/export', ['passphrase' => 'short']);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertStringNotContainsString('fleet-project-export', $this->body());
    }

    public function testExportingAnUnknownProjectIs404(): void
    {
        $this->post('/admin/projects/nope/export', []);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheImportPageShowsTheUploadForm(): void
    {
        $crawler = $this->open('/admin/import');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('form[action="/admin/import/preview"][enctype="multipart/form-data"]'));
        self::assertCount(1, $crawler->filter('[data-testid=import-file][type=file]'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAValidFileIsPreviewedWithoutWritingAnything(): void
    {
        $text = $this->exportAndRemove();
        self::assertSame(0, $this->projectCount());

        $this->upload($text);

        self::assertResponseStatusCodeSame(200);
        $crawler = $this->client->getCrawler();
        $actions = $crawler->filter('[data-testid=import-row]')->each(static fn ($row): string => (string) $row->attr('data-action'));
        self::assertNotEmpty($actions);
        self::assertSame(['create'], array_values(array_unique($actions)));
        self::assertCount(1, $crawler->filter('[data-testid=import-confirm-form] input[type=hidden][name=document]'));
        self::assertSame(0, $this->projectCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'bad JSON' => ['{not json'];
        yield 'wrong version' => ['{"format":"fleet-project-export","version":99}'];
        yield 'wrong format' => ['{"format":"something-else","version":1}'];
        yield 'unknown key' => ['{"format":"fleet-project-export","version":1,"surprise":true}'];
    }

    #[DataProvider('refusedFiles')]
    public function testARefusedFileGives422AndWritesNothing(string $content): void
    {
        $this->upload($content);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-errors]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=import-confirm-form]'));
        self::assertSame(0, $this->projectCount());
    }

    public function testAPreviewWithoutAFileIs422(): void
    {
        $this->post('/admin/import/preview', []);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-error]'));
    }

    public function testApplyCreatesTheProjectAndRedirectsToIt(): void
    {
        $text = $this->exportAndRemove();

        $this->post('/admin/import/apply', ['document' => $text]);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertSame(1, $this->projectCount());
        $this->open('/admin/projects/blog');
        self::assertResponseStatusCodeSame(200);
    }

    public function testApplyWithASealedFileImportsTheSecretsOnlyWithThePassphrase(): void
    {
        $text = $this->exportAndRemove(self::PASSPHRASE);

        $this->post('/admin/import/apply', ['document' => $text, 'passphrase' => self::PASSPHRASE]);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        self::assertSame(self::SECRET, $store->getManaged('blog', 'API_KEY'));
    }

    public function testApplyWithAWrongPassphraseWritesNothing(): void
    {
        $text = $this->exportAndRemove(self::PASSPHRASE);

        $this->post('/admin/import/apply', ['document' => $text, 'passphrase' => 'not the passphrase']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid=import-errors]'));
        self::assertSame(0, $this->projectCount());
    }

    public function testApplyWithABadDocumentWritesNothing(): void
    {
        $this->post('/admin/import/apply', ['document' => '{"format":"fleet-project-export","version":2}']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->projectCount());

        $this->post('/admin/import/apply', ['document' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->projectCount());
    }
}
