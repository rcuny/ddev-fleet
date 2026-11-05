<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\System;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\LogicalCpus;
use App\Fleet\System\PrivilegedApplyFailed;
use App\Fleet\System\SlicesApplier;
use App\Fleet\System\SlicesRenderer;
use App\Fleet\System\SystemRenderer;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RecordingApplier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The app side of `apply slices`: what is staged for the helper, and that a failure of the helper is a warning.
 */
final class SlicesApplierTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private ProjectRepository $projects;
    private TemplateRepository $templates;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->paths = self::getContainer()->get(FleetPaths::class);
        $this->projects = new ProjectRepository($em);
        $this->templates = new TemplateRepository($em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testStagesTheTreeForTheHelperAndRemovesTheFileAfterwards(): void
    {
        $blog = new Project('my-blog', 'https://git.example.com/acme/blog.git');
        $blog->changeCapacity(6, null);
        $this->projects->save($blog);
        $template = new Template($blog, 'ci-full', new \DateTimeImmutable());
        $template->changeCapacity(4, null);
        $this->templates->save($template);
        $this->projects->save(new Project('shop', 'https://git.example.com/acme/shop.git'));

        $applier = new class($this->paths) implements Applier {
            /** @var list<ApplyTarget> */
            public array $applied = [];
            public ?string $seen = null;

            public function __construct(private readonly FleetPaths $paths)
            {
            }

            public function apply(ApplyTarget $target): void
            {
                $this->seen = (string) file_get_contents($this->paths->stagingFile('slices', 'slices.json'));
                $this->applied[] = $target;
            }
        };

        $this->slices($applier, 16)->apply();

        self::assertSame([ApplyTarget::Slices], $applier->applied);
        // 16 threads minus the default reserve of 2.
        self::assertSame(
            [
                ['unit' => 'fleet.slice', 'quota_percent' => 1400],
                ['unit' => 'fleet-my_blog.slice', 'quota_percent' => 600],
                ['unit' => 'fleet-my_blog-ci_full.slice', 'quota_percent' => 400],
                ['unit' => 'fleet-shop.slice', 'quota_percent' => null],
            ],
            json_decode((string) $applier->seen, true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertFileDoesNotExist($this->paths->stagingFile('slices', 'slices.json'));
    }

    public function testAHelperFailureIsAWarningNotAnException(): void
    {
        $applier = new RecordingApplier(new PrivilegedApplyFailed('Applying "slices" failed (exit 65): refused'));
        $slices = $this->slices($applier, 8);

        $slices->sync();

        self::assertStringContainsString('the CPU limits were not applied on the host', (string) $slices->takeWarning());
        self::assertNull($slices->takeWarning(), 'a warning is shown once');
        self::assertFileDoesNotExist($this->paths->stagingFile('slices', 'slices.json'), 'the staged file is removed on failure too');
    }

    private function slices(Applier $applier, int $threads): SlicesApplier
    {
        $system = self::getContainer()->get(SystemRenderer::class);
        $cpus = new class($threads) implements LogicalCpus {
            public function __construct(private readonly int $threads)
            {
            }

            public function count(): int
            {
                return max(1, $this->threads);
            }
        };

        return new SlicesApplier($this->projects, self::getContainer()->get(HostSettingsRepository::class), $cpus, new SlicesRenderer(), $system, $applier);
    }
}
