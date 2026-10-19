<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Instances\DomainGitIdentity;
use App\Fleet\Instances\GitIdentityResolver;
use App\Fleet\Instances\InstanceConfigWriter;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;

final class InstanceConfigWriterTest extends KernelTestCase
{
    use FleetDatabase;

    private SecretStore $secrets;
    private InstanceConfigWriter $writer;
    private string $dir;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $host = new HostSettingsRepository($em);
        $host->getOrCreate('fleet.example.com');
        $hostnames = new InstanceHostnames($host);
        $this->secrets = new SecretStore(
            new SecretRepository($em),
            new SecretBox(new SecretKeyFile(self::getContainer()->get(FleetPaths::class))),
            new MockClock('2026-10-19 10:00:00 UTC'),
        );
        $this->writer = new InstanceConfigWriter($hostnames, new GitIdentityResolver(new DomainGitIdentity($hostnames)), $this->secrets);
        $this->dir = $this->tempHome.'/checkout';
        mkdir($this->dir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function project(GitBotMode $mode = GitBotMode::Inherit, string ...$hostnames): Project
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeGitBot($mode, null, null);
        $project->changeHostnames(array_values($hostnames));

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function written(Project $project, string $label = 'main'): array
    {
        $relative = $this->writer->write(new Instance($project, $label, 'full', 'develop', 'admin'), $project, $this->dir);
        self::assertSame('.ddev/config.fleet.yaml', $relative);
        self::assertSame(0o640, fileperms($this->dir.'/'.$relative) & 0o777);

        $config = Yaml::parseFile($this->dir.'/'.$relative);
        self::assertIsArray($config);

        return $config;
    }

    public function testKeysAndPerformanceModeNone(): void
    {
        $config = $this->written($this->project());

        self::assertSame('acme--main', $config['name']);
        self::assertSame('fleet.example.com', $config['project_tld']);
        self::assertSame('none', $config['performance_mode']);
        self::assertArrayNotHasKey('additional_fqdns', $config);
        self::assertArrayNotHasKey('hooks', $config);
    }

    public function testWebEnvironmentOrderAndValues(): void
    {
        $config = $this->written($this->project(GitBotMode::Inherit, 'edge'));

        self::assertSame([
            'DRUSH_OPTIONS_URI=https://acme--main.fleet.example.com',
            'FLEET_INSTANCE_HOST=acme--main.fleet.example.com',
            'GIT_AUTHOR_NAME=ddev-fleet bot',
            'GIT_AUTHOR_EMAIL=bot@fleet.example.com',
            'GIT_COMMITTER_NAME=ddev-fleet bot',
            'GIT_COMMITTER_EMAIL=bot@fleet.example.com',
        ], $config['web_environment']);
        self::assertSame(['edge-acme--main.fleet.example.com'], $config['additional_fqdns']);
    }

    public function testClaudeTokenOnlyWhenTheSecretExists(): void
    {
        self::assertNotContains('CLAUDE_CODE_OAUTH_TOKEN=tok-1', $this->written($this->project())['web_environment']);

        $this->secrets->putFleetWide(HostSecrets::CLAUDE_TOKEN, 'tok-1', 'admin');
        $environment = $this->written($this->project())['web_environment'];

        self::assertSame('CLAUDE_CODE_OAUTH_TOKEN=tok-1', $environment[2]);
    }

    public function testDisabledIdentityWritesNoGitVars(): void
    {
        $environment = $this->written($this->project(GitBotMode::Disabled))['web_environment'];

        self::assertSame(2, \count($environment));
        self::assertSame([], array_filter($environment, static fn (string $e): bool => str_starts_with($e, 'GIT_')));
    }

    public function testHostileValuesCannotBreakTheYaml(): void
    {
        $project = $this->project(GitBotMode::Override);
        $project->changeGitBot(GitBotMode::Override, "Acme: {x}\n- injected: 'y' #", 'a@example.com');

        $config = $this->written($project);

        self::assertSame("GIT_AUTHOR_NAME=Acme: {x}\n- injected: 'y' #", $config['web_environment'][2]);
        self::assertCount(6, $config['web_environment']);
        self::assertSame(['name', 'project_tld', 'performance_mode', 'web_environment'], array_keys($config));
    }
}
