<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\HostRenderCommand;
use App\Domain\Host\TlsMode;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\ProjectAccess;
use App\Domain\Project\ProjectAccessRepository;
use App\Fleet\Auth\AccessModelUpgrade;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\System\CaddyReloader;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\SetupFixture;
use App\Tests\Support\SystemTemplates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Yaml\Yaml;

final class HostRenderCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;

    private SetupFixture $fx;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $em = $this->em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function render(array $options = []): CommandTester
    {
        $tester = new CommandTester(new HostRenderCommand($this->fx->settings, $this->fx->system, $this->upgradeStep(false)));
        $tester->execute($options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function gate(): string
    {
        return (string) @file_get_contents($this->fx->paths->gateFile());
    }

    public function testAutoPicksSetupGateUntilComplete(): void
    {
        $host = $this->fx->host();

        $open = $this->render();
        self::assertSame(0, $open->getStatusCode(), $open->getErrorOutput());
        self::assertStringContainsString('Gate: setup.', $open->getDisplay());
        self::assertStringNotContainsString('forward_auth', $this->gate());
        self::assertSame([], $this->fx->staged(), 'nothing is staged without --stage or --apply');
        self::assertSame(['caddy:validate', 'caddy:reload'], $this->fx->events);

        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
        $done = $this->render();
        self::assertStringContainsString('Gate: authelia.', $done->getDisplay());
        self::assertStringContainsString('forward_auth', $this->gate());
    }

    public function testStageLeavesTheRenderedFilesForTheRootHelper(): void
    {
        $this->fx->host();

        $tester = $this->render(['--stage' => true, '--no-reload' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('Staged: caddy, authelia.', $tester->getDisplay());
        self::assertSame(['authelia/configuration.yml', 'caddy/Caddyfile', 'caddy/env'], $this->fx->staged());
    }

    public function testARenderWithoutApplyDiscardsWhatAnEarlierStageLeftBehind(): void
    {
        $this->fx->host(TlsMode::OvhDns);
        $this->fx->addOvhSecrets();
        $this->render(['--stage' => true, '--no-reload' => true]);
        self::assertContains('caddy/env', $this->fx->staged(), 'the OVH credentials are staged in clear');

        $tester = $this->render(['--no-reload' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame([], $this->fx->staged(), 'a render nobody applies leaves no credential on disk');
    }

    public function testAnOvhRenderWithoutApplyNeverStagesTheCredentials(): void
    {
        $this->fx->host(TlsMode::OvhDns);
        $this->fx->addOvhSecrets();

        $tester = $this->render();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame([], $this->fx->staged());
        self::assertStringContainsString('nothing staged', $tester->getDisplay());
    }

    public function testNoReloadOnlyWritesTheGateFile(): void
    {
        $this->fx->host();

        $tester = $this->render(['--no-reload' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame([], $this->fx->events, 'no caddy validate/reload (Caddy is not installed yet)');
        self::assertFileExists($this->fx->paths->gateFile());
    }

    public function testExplicitGateOverridesTheState(): void
    {
        $this->fx->host();

        $tester = $this->render(['--gate' => 'authelia', '--no-reload' => true]);

        self::assertStringContainsString('forward_auth', $this->gate());
        self::assertStringContainsString('Gate: authelia.', $tester->getDisplay());
    }

    public function testApplyInstallsAndRemovesWhatItStaged(): void
    {
        $this->fx->host(TlsMode::OvhDns);
        $this->fx->addOvhSecrets();

        $tester = $this->render(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame(['apply:authelia', 'apply:caddy', 'caddy:validate', 'caddy:reload'], $this->fx->events);
        self::assertSame([], $this->fx->staged(), 'the OVH credentials do not stay behind');
    }

    public function testAFailureStagesNothingAndExitsNonZero(): void
    {
        $this->fx->host(TlsMode::OvhDns); // no OVH secrets yet

        $tester = $this->render();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('ovh_', $tester->getErrorOutput());
        self::assertSame([], $this->fx->staged());
        self::assertFileDoesNotExist($this->fx->paths->gateFile());
    }

    public function testAnInvalidGateOptionAndAMissingHostFail(): void
    {
        self::assertSame(1, $this->render(['--gate' => 'nope'])->getStatusCode());

        $noHost = $this->render();
        self::assertSame(1, $noHost->getStatusCode());
        self::assertStringContainsString('fleet:host:init', $noHost->getErrorOutput());
    }

    public function testTheSetupGateOnACompletedInstallNeedsForce(): void
    {
        $host = $this->fx->host();
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
        $this->render(['--no-reload' => true]);

        $refused = $this->render(['--gate' => 'setup', '--no-reload' => true]);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('fleet:setup:reset', $refused->getErrorOutput());
        self::assertStringContainsString('forward_auth', $this->gate(), 'the gate was not touched');

        $forced = $this->render(['--gate' => 'setup', '--force' => true, '--no-reload' => true]);
        self::assertSame(0, $forced->getStatusCode(), $forced->getErrorOutput());
        self::assertStringContainsString('--force', $forced->getErrorOutput(), 'a warning is printed');
        self::assertStringNotContainsString('forward_auth', $this->gate());
    }

    /**
     * A completed host with a member of project `acme` and a running instance whose site is the old one (no group check),
     * plus the upgrade step wired to a Caddy runner that records into the fixture's event list.
     */
    private function oldHostWithAnInstance(bool $caddyFails): AccessModelUpgrade
    {
        $host = $this->fx->host();
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
        $this->fx->userService->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
        $project = $this->projectNamed('acme');
        [$instance] = $this->instancesOf($project, [InstanceState::Running]);
        $user = $this->fx->users->findByUsername('alice');
        self::assertNotNull($user);
        $access = new ProjectAccessRepository($this->em);
        $access->add(new ProjectAccess($user, $project));
        $access->flush();
        @mkdir($this->fx->paths->caddyInstancesDir(), 0o750, true);
        file_put_contents($this->fx->paths->instanceSiteFile($instance->id()), "# old site, no group check\n");

        return $this->upgradeStep($caddyFails);
    }

    /**
     * The upgrade step wired to a Caddy runner that records into the fixture's event list (and fails on request).
     */
    private function upgradeStep(bool $caddyFails): AccessModelUpgrade
    {
        $fixture = $this->fx;
        $runner = new class($fixture, $caddyFails) implements CommandRunner {
            public function __construct(private readonly SetupFixture $fixture, private readonly bool $fails)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $this->fixture->record('site:'.$argv[1]);

                return $this->fails ? new CommandResult(1, '', 'caddy says no') : new CommandResult(0);
            }
        };
        $writer = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->fx->paths,
            new CaddyReloader($runner, $this->fx->paths),
            new InstanceHostnames($this->fx->settings),
            new LockFactory(new FlockStore($this->fx->paths->locksDir())),
            $this->fx->admins,
        );

        return new AccessModelUpgrade(
            $this->fx->users,
            new AutheliaUsersFile($this->fx->paths, new AutheliaUsersRenderer(), null, new ProjectAccessRepository($this->em)),
            new InstanceSiteRefresher(new InstanceRepository($this->em), $writer, $this->fx->paths),
        );
    }

    public function testExistingSitesAndProjectGroupsAreWrittenBeforeAutheliaGetsTheRelaxedRule(): void
    {
        $upgrade = $this->oldHostWithAnInstance(false);
        $groupsAtApply = null;
        $siteAtApply = null;
        $this->fx->probe = function (string $event) use (&$groupsAtApply, &$siteAtApply): void {
            if ('apply:authelia' === $event) {
                $groupsAtApply = Yaml::parseFile($this->fx->paths->usersFile())['users']['alice']['groups'];
                $siteAtApply = (string) file_get_contents($this->fx->paths->instanceSiteFile('acme--i1'));
            }
        };

        $tester = new CommandTester(new HostRenderCommand($this->fx->settings, $this->fx->system, $upgrade));
        $tester->execute(['--apply' => true, '--no-reload' => true], ['capture_stderr_separately' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('written to 1 instance site(s)', $tester->getDisplay());
        self::assertContains('fleet-project-acme', $groupsAtApply ?? [], 'users.yml carried the project groups when Authelia was installed');
        self::assertStringContainsString('@forbidden not header_regexp Remote-Groups', (string) $siteAtApply, 'the site already checked the group when Authelia was installed');
        self::assertSame(['site:validate', 'site:reload', 'apply:authelia', 'apply:caddy'], \array_slice($this->fx->events, 0, 4));
    }

    public function testASiteThatCannotBeRewrittenKeepsAutheliaAsItIs(): void
    {
        $upgrade = $this->oldHostWithAnInstance(true);

        foreach (['--apply', '--stage'] as $mode) {
            $tester = new CommandTester(new HostRenderCommand($this->fx->settings, $this->fx->system, $upgrade));
            $tester->execute([$mode => true, '--no-reload' => true], ['capture_stderr_separately' => true]);

            self::assertSame(1, $tester->getStatusCode(), $mode);
            self::assertStringContainsString('could not be rewritten', $tester->getErrorOutput());
            self::assertStringContainsString('Authelia was left as it is', $tester->getErrorOutput());
        }
        self::assertNotContains('apply:authelia', $this->fx->events, 'fail closed: the old per-project rules stay installed');
        self::assertSame([], $this->fx->staged(), 'nothing is left staged for the installer to apply');
        self::assertSame("# old site, no group check\n", file_get_contents($this->fx->paths->instanceSiteFile('acme--i1')), 'the failed rewrite was rolled back');
    }

    public function testNothingIsRewrittenBeforeSetupIsComplete(): void
    {
        $upgrade = $this->oldHostWithAnInstance(false);
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        $this->fx->events = [];
        $incomplete = new \App\Domain\Host\HostSettings('fleet.example.com');

        self::assertSame(0, $upgrade->prepare($incomplete));
        self::assertSame([], $this->fx->events);
    }
}
