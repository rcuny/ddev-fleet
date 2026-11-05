<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FLE-199: --cpu-threads and --slots on the project and template commands, the validation of the two levels and the
 * round trip of nulls.
 */
final class CapacityCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testNullsRoundTripAndNoneClears(): void
    {
        $shown = $this->json($this->cli('fleet:project:show', ['name' => 'blog', '--json' => true]));
        self::assertNull($shown['cpu_threads']);
        self::assertNull($shown['slots']);

        $set = $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '24', '--slots' => '3', '--json' => true]);
        self::assertSame(0, $set->getStatusCode(), $set->getErrorOutput());
        $object = $this->json($set);
        self::assertSame(24, $object['cpu_threads']);
        self::assertSame(3, $object['slots']);

        // an update without the options keeps them
        $this->cli('fleet:project:update', ['name' => 'blog', '--default-branch' => 'develop']);
        $kept = $this->json($this->cli('fleet:project:show', ['name' => 'blog', '--json' => true]));
        self::assertSame(24, $kept['cpu_threads']);

        $cleared = $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => 'none', '--slots' => 'none', '--json' => true]);
        self::assertSame(0, $cleared->getStatusCode(), $cleared->getErrorOutput());
        self::assertNull($this->json($cleared)['cpu_threads']);
        self::assertNull($this->json($cleared)['slots']);

        $text = $this->cli('fleet:project:show', ['name' => 'blog']);
        self::assertStringContainsString('cpu threads: unlimited', $text->getDisplay());
    }

    public function testTemplateRoundTripAndShow(): void
    {
        $created = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--cpu-threads' => '4', '--slots' => '2', '--json' => true]);
        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertSame(4, $this->json($created)['cpu_threads']);
        self::assertSame(2, $this->json($created)['slots']);
        self::assertStringContainsString('cpu threads: 4', $this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web'])->getDisplay());

        $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--cpu-threads' => 'none', '--slots' => 'none']);
        $shown = $this->json($this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web', '--json' => true]));
        self::assertNull($shown['cpu_threads']);
        self::assertNull($shown['slots']);
        self::assertStringContainsString("inherits the project's", $this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web'])->getDisplay());
    }

    public function testTemplateAboveProjectIsRefusedNamingTheLimit(): void
    {
        $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '8', '--slots' => '2']);

        $cpu = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--cpu-threads' => '9']);
        self::assertSame(1, $cpu->getStatusCode());
        self::assertStringContainsString('--cpu-threads', $cpu->getErrorOutput());

        $slots = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--slots' => '3']);
        self::assertSame(1, $slots->getStatusCode());
        self::assertStringContainsString('--slots', $slots->getErrorOutput());

        $fine = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--cpu-threads' => '8', '--slots' => '2']);
        self::assertSame(0, $fine->getStatusCode(), $fine->getErrorOutput());
    }

    public function testLoweringTheProjectBelowATemplateNamesTheTemplate(): void
    {
        $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '10', '--slots' => '4']);
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'ci-full', '--cpu-threads' => '8', '--slots' => '3']);

        $cpu = $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '6']);
        self::assertSame(1, $cpu->getStatusCode());
        self::assertStringContainsString('ci-full', $cpu->getErrorOutput());

        $slots = $this->cli('fleet:project:update', ['name' => 'blog', '--slots' => '2']);
        self::assertSame(1, $slots->getStatusCode());
        self::assertStringContainsString('ci-full', $slots->getErrorOutput());

        // nothing changed
        $shown = $this->json($this->cli('fleet:project:show', ['name' => 'blog', '--json' => true]));
        self::assertSame(10, $shown['cpu_threads']);
        self::assertSame(4, $shown['slots']);

        // a template that inherits never blocks, and unlimited is always fine
        $unlimited = $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => 'none', '--slots' => 'none']);
        self::assertSame(0, $unlimited->getStatusCode(), $unlimited->getErrorOutput());
    }

    public function testProjectAboveAllocatableIsNotAnError(): void
    {
        $result = $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '4096']);

        self::assertSame(0, $result->getStatusCode(), $result->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badValues(): iterable
    {
        yield 'zero' => ['--cpu-threads', '0'];
        yield 'negative' => ['--cpu-threads', '-2'];
        yield 'text' => ['--slots', 'many'];
        yield 'decimal' => ['--slots', '1.5'];
        yield 'zero slots' => ['--slots', '0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function testBadValuesAreRefused(string $option, string $value): void
    {
        $result = $this->cli('fleet:project:update', ['name' => 'blog', $option => $value]);

        self::assertSame(1, $result->getStatusCode());
        self::assertStringContainsString($option, $result->getErrorOutput());
    }

    public function testImportKeepsHostCapacity(): void
    {
        // the fields are not part of the export file: an update through ProjectInput::with() must not wipe them
        $projects = self::getContainer()->get(ProjectService::class);
        $this->cli('fleet:project:update', ['name' => 'blog', '--cpu-threads' => '5', '--slots' => '2']);
        $this->entityManager()->clear();
        $input = ProjectInput::fromProject($projects->get('blog'));

        self::assertSame(5, $input->cpuThreads);
        self::assertSame(2, $input->slots);
    }
}
