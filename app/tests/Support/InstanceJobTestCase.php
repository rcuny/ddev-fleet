<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Secret\SecretRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Instances\ContextTokens;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\DeployWorkflow;
use App\Fleet\Instances\DomainGitIdentity;
use App\Fleet\Instances\DrupalSettingsWriter;
use App\Fleet\Instances\GitIdentityResolver;
use App\Fleet\Instances\InstanceConfigWriter;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Instances\Teardown;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyReloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Mercure\HubInterface;

/**
 * The instance job types wired by hand over a migrated database: the recording runner, a mock clock, a real fleet home
 * with a domain, a project `acme` with a template `full`, and helpers for instances, jobs and their logs.
 */
abstract class InstanceJobTestCase extends KernelTestCase
{
    use FleetDatabase;

    protected EntityManagerInterface $em;
    protected FleetPaths $paths;
    protected RecordingCommandRunner $runner;
    protected MockClock $clock;
    protected InstanceRepository $instances;
    protected TemplateRepository $templates;
    protected Project $project;
    protected Template $template;
    protected Ddev $ddev;
    protected InstanceSiteWriter $sites;
    protected Teardown $teardown;
    protected JobFeed $feed;
    protected RegisteredSecretsMasker $masker;
    protected SecretStore $secretStore;
    protected DeployWorkflow $workflow;

    /** @var \ArrayObject<int, DeployStep> */
    protected \ArrayObject $steps;

    protected function setUp(): void
    {
        $this->steps = new \ArrayObject();
        $this->masker = new RegisteredSecretsMasker();
        $this->em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->runner = new RecordingCommandRunner();
        $this->clock = new MockClock('2026-10-20 09:00:00 UTC');
        $this->instances = new InstanceRepository($this->em);
        $this->templates = new TemplateRepository($this->em);

        $host = new HostSettingsRepository($this->em);
        $host->getOrCreate('fleet.example.com');
        $hostnames = new InstanceHostnames($host);
        $this->secretStore = $secrets = new SecretStore(new SecretRepository($this->em), new SecretBox(new SecretKeyFile($this->paths)), $this->clock);
        $this->ddev = new Ddev($this->runner, '/home/fleet');
        $this->sites = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->paths,
            new CaddyReloader($this->runner, $this->paths),
            $hostnames,
            new LockFactory(new FlockStore($this->paths->locksDir())),
        );
        $this->teardown = new Teardown($this->paths, $this->ddev, $this->sites);
        $this->feed = new JobFeed($this->createStub(HubInterface::class), new NullLogger());
        $this->workflow = new DeployWorkflow(
            $this->instances,
            $this->templates,
            new GitClient($this->runner, $this->paths),
            $this->ddev,
            new InstanceConfigWriter($hostnames, new GitIdentityResolver(new DomainGitIdentity($hostnames)), $secrets, $this->masker),
            new DrupalSettingsWriter(),
            new ContextTokens($hostnames),
            $hostnames,
            $this->sites,
            $this->teardown,
            $this->paths,
            $this->feed,
            $this->clock,
            $this->steps,
        );

        $this->project = new ProjectRepository($this->em)->findByName('acme') ?? $this->saveProject();
        $this->template = $this->saveTemplate('full', []);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function saveProject(): Project
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        new ProjectRepository($this->em)->save($project);

        return $project;
    }

    /**
     * @param list<string> $postDeploy
     */
    protected function saveTemplate(string $name, array $postDeploy, ?string $drupalEnv = null): Template
    {
        $template = $this->templates->find($this->project, $name) ?? new Template($this->project, $name);
        $template->changeCommands($postDeploy, [], []);
        $template->changeDrupalEnv($drupalEnv);
        $this->templates->save($template);

        return $template;
    }

    protected function instance(string $label = 'main', string $branch = 'develop', InstanceState $state = InstanceState::Deploying, string $template = 'full'): Instance
    {
        $instance = new Instance($this->project, $label, $template, $branch, 'admin', $this->clock->now());
        $instance->changeState($state, $this->clock->now());
        $this->instances->createMany([$instance]);

        return $instance;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function job(string $type, Instance $instance, array $params = []): Job
    {
        $job = new Job($type, $instance->id(), $instance->project()->name(), JobTrigger::Ui, 'admin', $params, null, false, null, $this->clock->now());
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    protected function logFor(Job $job): JobLog
    {
        return new JobLog($this->paths->jobLog((string) $job->id(), $job->instanceId()), $this->masker, $this->feed, $job);
    }

    protected function logText(Job $job): string
    {
        $path = $this->paths->jobLog((string) $job->id(), $job->instanceId());

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * A checkout with a `.git` and one project file.
     */
    protected function checkout(Instance $instance): string
    {
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.git/info', 0o750, true);
        file_put_contents($dir.'/index.php', 'old');

        return $dir;
    }

    /**
     * @return list<list<string>> the argv of every recorded call
     */
    protected function argvs(): array
    {
        return array_map(static fn ($call): array => $call->argv, $this->runner->calls());
    }

    /**
     * @return list<string> "git clone", "ddev start", "ddev init", ... in the order they ran
     */
    protected function commandNames(): array
    {
        return array_map(static function (array $argv): string {
            $words = [$argv[0]];
            foreach (\array_slice($argv, 1) as $word) {
                if (str_starts_with($word, '-') || str_contains($word, '=')) {
                    continue;
                }
                $words[] = $word;
                break;
            }

            return implode(' ', $words);
        }, $this->argvs());
    }
}
