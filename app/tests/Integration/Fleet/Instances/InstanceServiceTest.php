<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\DeployInput;
use App\Fleet\Instances\DiskHeadroom;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Health\HostHealth;
use App\Tests\Support\JobTestCase;
use App\Tests\Support\SecondWriter;
use Doctrine\Persistence\ManagerRegistry;

final class InstanceServiceTest extends JobTestCase
{
    use SecondWriter;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $this->project->changeDefaults('develop', 'full');
        new ProjectRepository($this->em)->save($this->project);
        new TemplateRepository($this->em)->save(new Template($this->project, 'full'));
        new HostSettingsRepository($this->em)->getOrCreate('fleet.example.com');
    }

    private function service(float $minFree = 0.0): InstanceService
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new InstanceService(
            new ProjectRepository($this->em),
            new TemplateRepository($this->em),
            $this->instances,
            $this->jobs,
            $this->service,
            $this->em,
            $registry,
            $this->paths,
            new DiskHeadroom(new HostHealth($this->paths), $minFree),
            new InstanceHostnames(new HostSettingsRepository($this->em)),
            $this->clock,
        );
    }

    /**
     * @param list<Job> $jobs
     *
     * @return list<string>
     */
    private static function instanceIds(array $jobs): array
    {
        return array_map(static fn (Job $j): string => (string) $j->instanceId(), $jobs);
    }

    /**
     * @return list<Job>
     */
    private function deploy(DeployInput $in): array
    {
        return $this->service()->requestDeploy($in, JobTrigger::Ui, 'admin');
    }

    public function testDeployUsesProjectDefaults(): void
    {
        $jobs = $this->deploy(new DeployInput('acme'));

        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertSame('deploy', $job->type());
        self::assertSame('acme--develop', $job->instanceId());
        self::assertSame('acme', $job->projectName());
        self::assertSame(JobStatus::Queued, $job->status());
        self::assertSame('develop', $job->params()['branch']);
        self::assertSame('full', $job->params()['template']);
        self::assertSame('develop', $job->params()['requestedLabel']);
        self::assertNull($job->batchId());
        self::assertFalse($job->isSequential());

        $instance = $this->instances->find('acme--develop');
        self::assertNotNull($instance);
        self::assertSame(InstanceState::Deploying, $instance->state());
        self::assertSame('admin', $instance->requestedBy());
        self::assertCount(1, $this->bus->sent, 'dispatched after the rows were written');
    }

    public function testBranchSlugBecomesTheLabel(): void
    {
        $jobs = $this->deploy(new DeployInput('acme', branch: 'feature/PWD8_1781'));

        self::assertSame('acme--feature-pwd8-1781', $jobs[0]->instanceId());
        self::assertSame('feature/PWD8_1781', $jobs[0]->params()['branch']);
    }

    public function testMissingDefaultBranchIsAFieldError(): void
    {
        $this->project->changeDefaults(null, 'full');
        new ProjectRepository($this->em)->save($this->project);

        try {
            $this->deploy(new DeployInput('acme'));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('branch', $e->errors);
        }
        self::assertSame([], $this->instances->ids());
    }

    public function testUnknownTemplateIsAFieldError(): void
    {
        try {
            $this->deploy(new DeployInput('acme', template: 'ghost'));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['template'], array_keys($e->errors));
        }
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->deploy(new DeployInput('ghost'));
    }

    public function testBranchWithLeadingDashIsRefused(): void
    {
        try {
            $this->deploy(new DeployInput('acme', branch: '--upload-pack=x'));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('branch', $e->errors);
        }
    }

    public function testALabelThatMakesTheIdTooLongIsAFieldError(): void
    {
        try {
            $this->deploy(new DeployInput('acme', label: str_repeat('a', 58)));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['label'], array_keys($e->errors));
        }
        self::assertSame([], $this->instances->ids());
    }

    public function testHostnameAliasesThatDoNotFitAreAFieldError(): void
    {
        $this->project->changeHostnames(['edge']);
        new ProjectRepository($this->em)->save($this->project);

        $this->expectException(ValidationFailed::class);
        $this->deploy(new DeployInput('acme', label: str_repeat('a', 56)));
    }

    public function testExistingLabelGetsSuffixNeverOverwrites(): void
    {
        $first = $this->deploy(new DeployInput('acme'));
        $second = $this->deploy(new DeployInput('acme'));
        $third = $this->deploy(new DeployInput('acme'));

        self::assertSame(['acme--develop'], self::instanceIds($first));
        self::assertSame(['acme--develop-1'], self::instanceIds($second));
        self::assertSame(['acme--develop-2'], self::instanceIds($third));
        self::assertSame('develop', $second[0]->params()['requestedLabel']);
        self::assertSame('develop-1', $second[0]->params()['label']);
    }

    public function testDirectoryWithGitOccupiesTheLabelButAStubDoesNot(): void
    {
        mkdir($this->paths->instanceDir('acme--develop').'/.git', 0o750, true);
        mkdir($this->paths->instanceDir('acme--develop-1'), 0o750, true); // a half-cloned stub: no .git

        $jobs = $this->deploy(new DeployInput('acme'));

        self::assertSame(['acme--develop-1'], self::instanceIds($jobs));
    }

    public function testCountBatchSuffixesFromOneAndSharesABatch(): void
    {
        $jobs = $this->deploy(new DeployInput('acme', count: 3));

        self::assertSame(['acme--develop-1', 'acme--develop-2', 'acme--develop-3'], self::instanceIds($jobs));
        $batch = $jobs[0]->batchId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $batch);
        foreach ($jobs as $job) {
            self::assertSame($batch, $job->batchId());
        }
        self::assertCount(3, $this->instances->ids());
    }

    public function testCountAboveTwentyIsRefused(): void
    {
        foreach ([0, 21] as $count) {
            try {
                $this->deploy(new DeployInput('acme', count: $count));
                self::fail('expected ValidationFailed');
            } catch (ValidationFailed $e) {
                self::assertSame(['count'], array_keys($e->errors));
            }
        }
        self::assertCount(20, $this->deploy(new DeployInput('acme', count: 20)));
    }

    public function testBatchIsSequentialUnlessParallel(): void
    {
        $sequential = $this->deploy(new DeployInput('acme', label: 'one', count: 2));
        $parallel = $this->deploy(new DeployInput('acme', label: 'two', count: 2, parallel: true));

        self::assertTrue($sequential[0]->isSequential() && $sequential[1]->isSequential());
        self::assertFalse($parallel[0]->isSequential() || $parallel[1]->isSequential());
        self::assertNotNull($parallel[0]->batchId());
    }

    public function testDiskHeadroomRefusesBatchesOnly(): void
    {
        $full = $this->service(minFree: 1.01);

        $single = $full->requestDeploy(new DeployInput('acme'), JobTrigger::Ui, 'admin');
        self::assertCount(1, $single);

        try {
            $full->requestDeploy(new DeployInput('acme', count: 2), JobTrigger::Ui, 'admin');
            self::fail('expected Refused');
        } catch (Refused $e) {
            self::assertStringContainsString('% of the disk is free', $e->getMessage());
        }
        self::assertCount(1, $this->instances->ids(), 'a refused batch creates nothing');

        $skipped = $full->requestDeploy(new DeployInput('acme', count: 2, skipDiskCheck: true), JobTrigger::Ui, 'admin');
        self::assertCount(2, $skipped);
    }

    public function testSimultaneousRequestsForOneLabelGetDifferentSuffixes(): void
    {
        $second = $this->secondEntityManager($this->em);
        $this->beforeNextPersist($this->em, function () use ($second): void {
            $project = $second->find(Project::class, $this->project->id());
            self::assertNotNull($project);
            $second->persist(new Instance($project, 'develop', 'full', 'develop', 'other'));
            $second->flush();
        });

        $jobs = $this->deploy(new DeployInput('acme'));

        self::assertSame(['acme--develop-1'], self::instanceIds($jobs));
        self::assertSame(['acme--develop', 'acme--develop-1'], $this->instances->ids());
        self::assertCount(1, $this->bus->sent, 'the lost attempt dispatched nothing');
    }

    public function testStopStartAndDestroyOfOneInstance(): void
    {
        $this->deploy(new DeployInput('acme'));
        $this->markFinished();

        $start = $this->service()->request('start', new Selector(['acme--develop']), new OperationOptions(timeout: 120.0, retryPortConflict: true), JobTrigger::Cli, 'cli:alice');
        self::assertSame('start', $start[0]->type());
        self::assertSame(['timeout' => 120.0, 'retryPortConflict' => true], $start[0]->params());
        self::assertNull($start[0]->batchId());
        self::assertSame(InstanceState::Deploying, $this->instances->find('acme--develop')?->state(), 'start does not touch the state');
        $this->markFinished();

        $destroy = $this->service()->request('destroy', new Selector(['acme--develop']), new OperationOptions(), JobTrigger::Ui, 'admin');
        self::assertSame('destroy', $destroy[0]->type());
        self::assertSame(InstanceState::Destroying, $this->instances->find('acme--develop')?->state());
    }

    public function testBulkDestroyIsSequential(): void
    {
        $this->deploy(new DeployInput('acme', count: 2, parallel: true));
        $this->markFinished();

        $jobs = $this->service()->request('destroy', new Selector(project: 'acme'), new OperationOptions(), JobTrigger::Ui, 'admin');

        self::assertCount(2, $jobs);
        self::assertTrue($jobs[0]->isSequential() && $jobs[1]->isSequential());
        self::assertSame($jobs[0]->batchId(), $jobs[1]->batchId());
        self::assertNotNull($jobs[0]->batchId());
    }

    public function testBulkStartIsConcurrentUnlessAsked(): void
    {
        $this->deploy(new DeployInput('acme', count: 2, parallel: true));
        $this->markFinished();

        $jobs = $this->service()->request('start', new Selector(all: true), new OperationOptions(), JobTrigger::Ui, 'admin');
        self::assertFalse($jobs[0]->isSequential());
        $this->markFinished();

        $jobs = $this->service()->request('stop', new Selector(all: true), new OperationOptions(sequential: true), JobTrigger::Ui, 'admin');
        self::assertTrue($jobs[0]->isSequential());
    }

    public function testRedeployCanSwitchTemplate(): void
    {
        $this->deploy(new DeployInput('acme'));
        $this->markFinished();
        new TemplateRepository($this->em)->save(new Template($this->project, 'light'));

        $jobs = $this->service()->request('redeploy', new Selector(['acme--develop']), new OperationOptions(template: 'light', force: true), JobTrigger::Ui, 'admin');

        self::assertSame(['template' => 'light', 'force' => true], $jobs[0]->params());
        self::assertSame(InstanceState::Deploying, $this->instances->find('acme--develop')?->state());

        $this->markFinished();
        $this->expectException(ValidationFailed::class);
        $this->service()->request('redeploy', new Selector(['acme--develop']), new OperationOptions(template: 'ghost'), JobTrigger::Ui, 'admin');
    }

    public function testUnknownIdIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->service()->request('start', new Selector(['acme--ghost']), new OperationOptions(), JobTrigger::Ui, 'admin');
    }

    public function testAnIdThatIsNotAnInstanceIdIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->service()->request('destroy', new Selector(['../../etc']), new OperationOptions(), JobTrigger::Ui, 'admin');
    }

    public function testSelectorMustBeUnambiguous(): void
    {
        foreach ([new Selector(), new Selector(['acme--a'], all: true), new Selector(all: true, project: 'acme')] as $selector) {
            try {
                $this->service()->request('stop', $selector, new OperationOptions(), JobTrigger::Ui, 'admin');
                self::fail('expected ValidationFailed');
            } catch (ValidationFailed $e) {
                self::assertSame(['selector'], array_keys($e->errors));
            }
        }
    }

    public function testBusyInstanceRefusesTheWholeRequest(): void
    {
        $this->deploy(new DeployInput('acme', count: 2, parallel: true)); // both have a queued deploy job
        $this->markFinished('acme--develop-1');
        $before = \count($this->bus->sent);

        try {
            $this->service()->request('stop', new Selector(project: 'acme'), new OperationOptions(), JobTrigger::Ui, 'admin');
            self::fail('expected Refused');
        } catch (Refused $e) {
            self::assertStringContainsString('acme--develop-2', $e->getMessage());
        }

        self::assertCount($before, $this->bus->sent);
        self::assertCount(2, $this->jobs->search([], 1)['rows'], 'no job was created for the free instance either');
    }

    public function testRetryOfFailedDeployCreatesRedeploy(): void
    {
        $deploy = $this->deploy(new DeployInput('acme'))[0];
        $this->service->finish($deploy, JobStatus::Failed, 1, 'clone failed');
        $this->instances->find('acme--develop')?->changeState(InstanceState::Failed);

        $retry = $this->service()->retry((int) $deploy->id(), JobTrigger::Ui, 'admin');

        self::assertSame('redeploy', $retry->type());
        self::assertSame($deploy->id(), $retry->retryOf());
        self::assertSame(InstanceState::Deploying, $this->instances->find('acme--develop')?->state());
    }

    public function testOnlyAFailedJobCanBeRetried(): void
    {
        $deploy = $this->deploy(new DeployInput('acme'))[0];

        $this->expectException(Refused::class);
        $this->service()->retry((int) $deploy->id(), JobTrigger::Ui, 'admin');
    }

    public function testRetryOfAnUnknownJobIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->service()->retry(999, JobTrigger::Ui, 'admin');
    }

    /**
     * Marks the queued and running jobs of one instance (or all) as succeeded, so the next request is not "busy".
     */
    private function markFinished(?string $instanceId = null): void
    {
        foreach ($this->jobs->search([], 1, 200)['rows'] as $job) {
            if (!$job->status()->isFinal() && (null === $instanceId || $job->instanceId() === $instanceId)) {
                $this->service->finish($job, JobStatus::Succeeded, 0, null);
            }
        }
    }

    public function testRetryOfAStartLeavesTheStateAlone(): void
    {
        $this->deploy(new DeployInput('acme'));
        $this->markFinished('acme--develop');
        $this->instances->find('acme--develop')?->changeState(InstanceState::Stopped);
        $start = $this->service()->request('start', new Selector(['acme--develop']), new OperationOptions(), JobTrigger::Ui, 'admin')[0];
        $this->service->finish($start, JobStatus::Failed, 1, 'port conflict');

        $retry = $this->service()->retry((int) $start->id(), JobTrigger::Ui, 'admin');

        self::assertSame('start', $retry->type());
        self::assertSame(InstanceState::Stopped, $this->instances->find('acme--develop')?->state());
    }
}
