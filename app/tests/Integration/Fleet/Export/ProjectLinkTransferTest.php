<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Export;

use App\Domain\Project\ProjectLinkRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\ImportItem;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Export\ProjectImporter;
use App\Fleet\Projects\ProjectLinkService;
use App\Tests\Support\ExportFixtures;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** FLE-299: declared links travel in the export as the additive `links` key and are skipped when the target is missing. */
final class ProjectLinkTransferTest extends KernelTestCase
{
    use ExportFixtures;
    use FleetDatabase;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function links(): ProjectLinkService
    {
        $service = $this->service(ProjectLinkService::class);
        \assert($service instanceof ProjectLinkService);

        return $service;
    }

    public function testALinkIsExportedAndImportedWhenTheTargetExists(): void
    {
        $this->seedBlog();
        $this->service(ProjectRepository::class)->save(new \App\Domain\Project\Project('cms', 'https://git.example.com/x/cms.git'));
        $this->links()->create('blog', 'cms', 'typesense', 8108, 'same', 'search', 'admin');
        $exporter = $this->service(ProjectExporter::class);
        \assert($exporter instanceof ProjectExporter);
        $text = $exporter->export('blog', null, 'admin');
        $document = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([['target' => 'cms', 'service' => 'typesense', 'port' => 8108, 'label' => 'same', 'note' => 'search']], $document['links']);

        // Unchanged on the same host.
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);
        $plan = $importer->preview($text, null);
        $link = array_values(array_filter($plan->items, static fn (ImportItem $i): bool => 'link' === $i->kind));
        self::assertSame(ImportItem::UNCHANGED, $link[0]->action);

        // On another host the target is missing: skipped, not an error. Once it exists the link is created.
        $this->tearDownFleetDatabase();
        $this->setUpFleetDatabase();
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);
        $plan = $importer->preview($text, null);
        $link = array_values(array_filter($plan->items, static fn (ImportItem $i): bool => 'link' === $i->kind));
        self::assertSame(ImportItem::SKIPPED, $link[0]->action);
        self::assertSame('the target project is not on this host', $link[0]->note);
        self::assertSame([], $plan->errors);

        $this->service(ProjectRepository::class)->save(new \App\Domain\Project\Project('cms', 'https://git.example.com/x/cms.git'));
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);
        $importer->apply($text, null, 'admin');
        $stored = $this->service(ProjectLinkRepository::class)->all();
        self::assertCount(1, $stored);
        self::assertSame('typesense', $stored[0]->service());
    }

    public function testAnExportWithoutLinksHasNoLinksKeyAndAnOldFileStillParses(): void
    {
        $this->seedBlog();
        $exporter = $this->service(ProjectExporter::class);
        \assert($exporter instanceof ProjectExporter);
        $document = json_decode($exporter->export('blog', null, 'admin'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('links', $document);
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);
        self::assertSame([], $importer->preview(ExportFormat::encode($document), null)->errors);
    }

    public function testBadLinkValuesAreErrorsOfTheFile(): void
    {
        $this->seedBlog();
        $exporter = $this->service(ProjectExporter::class);
        \assert($exporter instanceof ProjectExporter);
        $document = json_decode($exporter->export('blog', null, 'admin'), true, 512, \JSON_THROW_ON_ERROR);
        $document['links'] = [['target' => 'blog', 'service' => 'Bad_Name', 'port' => 70000, 'label' => 'same', 'note' => '']];
        $importer = $this->service(ProjectImporter::class);
        \assert($importer instanceof ProjectImporter);

        $errors = $importer->preview(ExportFormat::encode($document), null)->errors;

        self::assertContains('links[0].target: a project does not need a link to itself.', $errors);
    }
}
