<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Schedule;

use App\Domain\Instance\InstanceState;
use App\Domain\Schedule\ScheduleRepository;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\RunsFleetCommands;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScheduleCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use RunsFleetCommands;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateAndUpdateHelpListTheRegisteredActions(): void
    {
        foreach (['fleet:schedule:create', 'fleet:schedule:update'] as $name) {
            self::assertNotNull(self::$kernel);
            $definition = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel)->find($name)->getDefinition();

            self::assertStringContainsString('one of: redeploy', $definition->getOption('action')->getDescription());
        }
    }

    public function testCreateListPauseResumeHistoryDelete(): void
    {
        $instance = $this->seedInstance();

        $created = $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => '0 3 * * *', '--timezone' => 'Europe/Brussels', '--instance' => $instance, '--json' => true]);
        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertSame('', $created->getErrorOutput());
        $shape = $this->json($created);
        self::assertSame(['nightly', '0 3 * * *', 'Europe/Brussels', 'redeploy', $instance, true], [$shape['name'], $shape['cron'], $shape['timezone'], $shape['action'], $shape['instance'], $shape['enabled']]);
        self::assertNotNull($shape['next_fire']);
        self::assertNull($shape['last_result']);

        $list = $this->cli('fleet:schedule:list');
        self::assertMatchesRegularExpression('#\bnightly 0 3 \* \* \* Europe/Brussels redeploy '.preg_quote($instance, '#').' active#', $this->squash($list->getDisplay()));

        $paused = $this->json($this->cli('fleet:schedule:pause', ['name' => 'nightly', '--json' => true]));
        self::assertFalse($paused['enabled']);
        self::assertNull($paused['next_fire']);
        self::assertTrue($this->json($this->cli('fleet:schedule:resume', ['name' => 'nightly', '--json' => true]))['enabled']);

        $updated = $this->json($this->cli('fleet:schedule:update', ['name' => 'nightly', '--cron' => '30 4 * * *', '--timezone' => 'UTC', '--instance' => $instance, '--json' => true]));
        self::assertSame('30 4 * * *', $updated['cron']);

        self::assertSame([], $this->json($this->cli('fleet:schedule:history', ['name' => 'nightly', '--json' => true])));

        $deleted = $this->cli('fleet:schedule:delete', ['name' => 'nightly', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode());
        self::assertSame(['deleted' => 'nightly'], $this->json($deleted));
        self::assertSame(3, $this->cli('fleet:schedule:pause', ['name' => 'nightly'])->getStatusCode());
    }

    public function testInvalidCronAndTimezoneNameTheOptions(): void
    {
        $bad = $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => '61 * * * *', '--timezone' => 'Mars/Base', '--instance' => 'x--y']);

        self::assertSame(1, $bad->getStatusCode());
        $lines = array_map(static fn (string $line): string => explode(':', $line)[0], array_filter(explode("\n", $bad->getErrorOutput())));
        self::assertSame(['--cron', '--timezone'], $lines);

        $json = $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => 'x', '--json' => true]);
        self::assertSame(1, $json->getStatusCode());
        self::assertSame('Invalid values.', $this->json($json)['error']);
        self::assertSame([], self::getContainer()->get(ScheduleRepository::class)->all());
    }

    public function testAnInstanceThatDoesNotExistYetIsAcceptedWithAWarning(): void
    {
        $created = $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => '0 3 * * *', '--instance' => 'later--main', '--json' => true]);

        self::assertSame(0, $created->getStatusCode());
        $shape = $this->json($created);
        self::assertSame('later--main', $shape['instance']);
        self::assertStringContainsString('later--main does not exist yet; runs are skipped until it does', $shape['warning']);

        $plain = $this->cli('fleet:schedule:update', ['name' => 'nightly', '--cron' => '0 4 * * *', '--instance' => 'later--main']);
        self::assertSame(0, $plain->getStatusCode());
        self::assertStringContainsString('does not exist yet', $plain->getErrorOutput());
    }

    public function testDeleteWithoutYesIsRefused(): void
    {
        $instance = $this->seedInstance();
        $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => '0 3 * * *', '--instance' => $instance]);

        self::assertSame(2, $this->cli('fleet:schedule:delete', ['name' => 'nightly'])->getStatusCode());
        self::assertCount(1, self::getContainer()->get(ScheduleRepository::class)->all());
    }

    public function testRunNowQueuesARedeployAndShowsInTheHistory(): void
    {
        $instance = $this->seedInstance();
        $this->cli('fleet:schedule:create', ['name' => 'nightly', '--cron' => '0 3 * * *', '--instance' => $instance]);
        $this->cli('fleet:schedule:pause', ['name' => 'nightly']);

        $run = $this->cli('fleet:schedule:run', ['name' => 'nightly', '--json' => true]);

        $shape = $this->json($run);
        self::assertSame('manual', $shape['trigger']);
        self::assertContains($run->getStatusCode(), [0, 2], $run->getErrorOutput());
        $history = $this->json($this->cli('fleet:schedule:history', ['name' => 'nightly', '--json' => true]));
        self::assertCount(1, $history);
        self::assertSame('manual', $history[0]['trigger']);
    }

    private function seedInstance(): string
    {
        $project = $this->projectNamed('acme');

        return $this->instancesOf($project, [InstanceState::Running])[0]->id();
    }
}
