<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Host\Capacity\SliceName;
use App\Fleet\Instances\InstanceCapacityEnvironment;
use App\Fleet\Instances\InstanceCgroupWriter;
use App\Fleet\Instances\SliceMembership;
use App\Fleet\Shell\CommandResult;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\LogicalCpus;
use App\Fleet\System\SlicesAvailability;
use App\Fleet\System\TargetSupport;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-202: the cgroup override, the capacity environment and the slot directories of a deploy (capacity spec, S4).
 */
final class InstanceCapacityDeployTest extends InstanceJobTestCase
{
    private const string COMPOSE_CONFIG = "name: acme--main\nservices:\n  db:\n    image: db\n  typesense:\n    image: ts\n  web:\n    image: web\n  xhgui:\n    image: xhgui\n";

    private string $cgroupRoot;
    private string $slotsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cgroupRoot = $this->paths->home().'/cgroup/fleet.slice';
        $this->slotsDir = $this->paths->home().'/slots';
    }

    private function availability(bool $available): SlicesAvailability
    {
        return new SlicesAvailability(new class($available) implements Applier, TargetSupport {
            public function __construct(private readonly bool $available)
            {
            }

            public function supports(ApplyTarget $target): bool
            {
                return $this->available;
            }

            public function apply(ApplyTarget $target): void
            {
            }
        });
    }

    private function cgroupWriter(bool $available = true): InstanceCgroupWriter
    {
        return new InstanceCgroupWriter($this->ddev, $this->templates, $this->availability($available), $this->cgroupRoot);
    }

    private function capacity(int $threads = 16): InstanceCapacityEnvironment
    {
        return new InstanceCapacityEnvironment($this->templates, new HostSettingsRepository($this->em), new class($threads) implements LogicalCpus {
            public function __construct(private readonly int $threads)
            {
            }

            public function count(): int
            {
                return max(1, $this->threads);
            }
        }, $this->slotsDir);
    }

    private function deployWith(InstanceCgroupWriter $cgroup, ?InstanceCapacityEnvironment $capacity = null): \App\Domain\Job\Job
    {
        $instance = $this->instance('main', 'develop');
        $job = $this->job('deploy', $instance);
        $this->workflowWith($cgroup, $capacity)->run($instance, $this->project, false, $this->logFor($job), false);

        return $job;
    }

    private function limitSlice(string $unit, string $quota): void
    {
        $dir = $this->cgroupRoot.'/'.$unit;
        mkdir($dir, 0o750, true);
        file_put_contents($dir.'/cpu.max', $quota."\n");
    }

    public function testEveryServiceGetsTheTemplateSliceBeforeTheStart(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));

        $job = $this->deployWith($this->cgroupWriter());

        $names = $this->commandNames();
        self::assertLessThan(array_search('ddev start', $names, true), array_search('ddev debug', $names, true), 'the services are listed before ddev start');
        $file = $this->paths->instanceDir('acme--main').'/'.InstanceCgroupWriter::RELATIVE_PATH;
        $override = Yaml::parseFile($file);
        $slice = SliceName::template('acme', 'full');
        self::assertSame('fleet-acme-full.slice', $slice);
        self::assertSame(['db', 'typesense', 'web', 'xhgui'], array_keys($override['services']));
        foreach ($override['services'] as $service) {
            self::assertSame(['cgroup_parent' => $slice], $service);
        }
        self::assertStringNotContainsString('#ddev-generated', (string) file_get_contents($file));
        self::assertSame(0o640, fileperms($file) & 0o777);
        self::assertStringContainsString(".ddev/docker-compose.fleet-cgroup.yaml\n", (string) file_get_contents($this->paths->instanceDir('acme--main').'/.git/info/exclude'));
        self::assertStringContainsString('deploy complete', $this->logText($job));
    }

    public function testAHostWithoutTheSlicesTargetDeploysAsBefore(): void
    {
        $this->deployWith($this->cgroupWriter(false));

        self::assertSame(['git clone', 'git config', 'caddy validate', 'caddy reload', 'ddev start', 'git rev-parse'], $this->commandNames());
        self::assertFileDoesNotExist($this->paths->instanceDir('acme--main').'/'.InstanceCgroupWriter::RELATIVE_PATH);
    }

    public function testAnUnreadableServiceListFailsTheDeployInsteadOfLeavingServicesUnlimited(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(1, '', 'no docker'));

        try {
            $this->deployWith($this->cgroupWriter());
            self::fail('expected the deploy to fail');
        } catch (\App\Fleet\Jobs\JobFailed $e) {
            self::assertStringContainsString('CPU slice override could not be written', $e->getMessage());
        }
        self::assertNotContains('ddev start', $this->commandNames());
    }

    public function testASliceWithoutQuotaIsReportedAfterTheStart(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));
        $template = $this->saveTemplate('full', []);
        $template->changeCapacity(4, null);
        $this->templates->save($template);
        $this->limitSlice(SliceName::project('acme').'/'.SliceName::template('acme', 'full'), 'max 100000');

        $job = $this->deployWith($this->cgroupWriter());

        self::assertStringContainsString('WARNING: the slice fleet-acme-full.slice has no CPU quota', $this->logText($job));
    }

    public function testAMissingSliceIsReportedAndAQuotaIsAccepted(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));
        $this->project->changeCapacity(6, null);
        $instance = $this->instance('main', 'develop');
        $writer = $this->cgroupWriter();

        self::assertStringContainsString('fleet-acme.slice was not found', (string) $writer->check($instance, $this->project));

        $this->limitSlice(SliceName::project('acme'), '600000 100000');
        self::assertNull($writer->check($instance, $this->project));
    }

    public function testNothingIsCheckedWhenNoLimitIsSet(): void
    {
        self::assertNull($this->cgroupWriter()->check($this->instance('main', 'develop'), $this->project));
    }

    public function testTheCapacityEnvironmentIsInjectedAndTheSlotDirectoriesExist(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));
        $this->project->changeCapacity(10, 3);
        $template = $this->saveTemplate('full', []);
        $template->changeCapacity(4, 2);
        $this->templates->save($template);

        $this->deployWith($this->cgroupWriter(), $this->capacity());

        $config = Yaml::parseFile($this->paths->instanceDir('acme--main').'/.ddev/config.fleet.yaml');
        $env = array_values(array_filter($config['web_environment'], static fn (string $line): bool => str_starts_with($line, 'FLEET_') && !str_starts_with($line, 'FLEET_INSTANCE_HOST')));
        self::assertSame([
            'FLEET_PROJECT_SLOTS=3',
            'FLEET_TEMPLATE_SLOTS=2',
            'FLEET_TEMPLATE_CPU_THREADS=4',
            'FLEET_SLOT_DIR='.$this->slotsDir.'/acme/full/',
            'FLEET_PROJECT_SLOT_DIR='.$this->slotsDir.'/acme/_project/',
        ], $env);
        self::assertDirectoryExists($this->slotsDir.'/acme/full');
        self::assertDirectoryExists($this->slotsDir.'/acme/_project');
    }

    public function testUnlimitedMeansEmptySlotsAndTheAllocatableThreads(): void
    {
        $instance = $this->instance('main', 'develop');

        $variables = $this->capacity(16)->variables($instance, $this->project);

        self::assertSame('FLEET_PROJECT_SLOTS=', $variables[0]);
        self::assertSame('FLEET_TEMPLATE_SLOTS=', $variables[1]);
        self::assertSame('FLEET_TEMPLATE_CPU_THREADS=14', $variables[2]); // 16 threads - the default reserve of 2
    }

    public function testATemplateLimitAboveTheProjectsIsCapped(): void
    {
        $this->project->changeCapacity(3, null);
        $template = $this->saveTemplate('full', []);
        $template->changeCapacity(8, null);
        $this->templates->save($template);

        $variables = $this->capacity()->variables($this->instance('main', 'develop'), $this->project);

        self::assertSame('FLEET_TEMPLATE_CPU_THREADS=3', $variables[2]);
    }

    public function testInstancesWithoutTheOverrideAreListedAsNotUnderASlice(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));
        $this->deployWith($this->cgroupWriter());
        $old = $this->instance('old', 'develop');
        mkdir($this->paths->instanceDir($old->id()).'/.ddev', 0o750, true);

        $membership = new SliceMembership($this->instances, $this->paths, $this->availability(true));

        self::assertSame([$old->id()], $membership->outside());
        self::assertSame([], new SliceMembership($this->instances, $this->paths, $this->availability(false))->outside());
    }

    public function testARestartRewritesTheOverrideAndTheEnvironment(): void
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, self::COMPOSE_CONFIG));
        $instance = $this->instance('main', 'develop', \App\Domain\Instance\InstanceState::Running);
        $dir = $this->checkout($instance);
        mkdir($dir.'/.ddev', 0o750, true);
        $this->project->changeCapacity(null, 5);
        $restart = new \App\Fleet\Jobs\Type\RestartJob(
            $this->instances,
            new \App\Fleet\Instances\InstanceConfigWriter($this->instanceHostnames, new \App\Fleet\Instances\GitIdentityResolver(new \App\Fleet\Instances\DomainGitIdentity($this->instanceHostnames)), $this->secretStore, $this->masker, new \App\Fleet\Typesense\TypesenseEnvironment(new \App\Fleet\Typesense\TypesenseKeys($this->secretStore), new \App\Domain\Port\NamedPortRepository($this->em), $this->instanceHostnames, $this->masker), $this->capacity()),
            $this->ddev,
            $this->paths,
            $this->feed,
            $this->cgroupWriter(),
        );
        $job = $this->job('restart', $instance);

        $restart->execute($job, $this->logFor($job));

        self::assertSame(['ddev debug', 'ddev restart'], $this->commandNames());
        self::assertFileExists($dir.'/'.InstanceCgroupWriter::RELATIVE_PATH);
        self::assertStringContainsString('FLEET_PROJECT_SLOTS=5', (string) file_get_contents($dir.'/.ddev/config.fleet.yaml'));
    }
}
