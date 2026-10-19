<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\RecordingCommandRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Instance and job command tests: the sync transport runs each job in the command, with the recording runner. A project
 * `acme` (default branch develop, template `full`) and a host domain exist.
 */
abstract class InstanceCliTestCase extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected EntityManagerInterface $em;
    protected Project $project;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $this->project->changeDefaults('develop', 'full');
        new ProjectRepository($this->em)->save($this->project);
        new TemplateRepository($this->em)->save(new Template($this->project, 'full'));
        new HostSettingsRepository($this->em)->getOrCreate('fleet.example.com');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $answers
     */
    protected function command(string $name, array $input = [], array $answers = [], bool $interactive = false): CommandTester
    {
        return $this->cli($name, $input, $answers, $interactive);
    }

    /**
     * @return array<mixed>
     */
    protected function decode(CommandTester $tester): array
    {
        return $this->json($tester);
    }

    protected function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.command_runner.recording');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    /**
     * Each gets a checkout directory, as a deployed instance has.
     *
     * @param list<InstanceState> $states labels i1, i2, ...
     *
     * @return list<Instance>
     */
    protected function instances(array $states): array
    {
        $made = [];
        foreach ($states as $i => $state) {
            $instance = new Instance($this->project, 'i'.($i + 1), 'full', 'develop', 'admin');
            $instance->changeState($state);
            $made[] = $instance;
        }
        new InstanceRepository($this->em)->createMany($made);
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        foreach ($made as $instance) {
            mkdir($paths->instanceDir($instance->id()).'/.git/info', 0o750, true);
        }

        return $made;
    }

    protected function stateOf(string $id): InstanceState
    {
        $this->em->clear();
        $instance = new InstanceRepository($this->em)->find($id);
        self::assertNotNull($instance);

        return $instance->state();
    }
}
