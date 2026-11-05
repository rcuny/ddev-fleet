<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Fleet\Host\Capacity\UsageSampler;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** FLE-200: fleet:host:capacity --json has the spec 5 shape (schema 1), the resolved limits, busy and live use. */
final class HostCapacityCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $home = $this->setUpFleetDatabaseHome();
        file_put_contents($home.'/test-cpuinfo', str_repeat("processor\t: 0\n\n", 48));
        file_put_contents($home.'/test-meminfo', "MemTotal:       197132288 kB\n");
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('ci', 'https://git.example.com/acme/ci.git'));
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput('free', 'https://git.example.com/acme/free.git'));
    }

    private function setUpFleetDatabaseHome(): string
    {
        $this->setUpFleetDatabase();

        return $this->tempHome;
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testJsonShapeAndResolvedLimits(): void
    {
        $this->cli('fleet:project:update', ['name' => 'ci', '--cpu-threads' => '24', '--slots' => '3']);
        $this->cli('fleet:template:create', ['project' => 'ci', 'name' => 'gates', '--slots' => '2']);
        $this->cli('fleet:template:create', ['project' => 'ci', 'name' => 'small', '--cpu-threads' => '4']);

        $run = $this->cli('fleet:host:capacity', ['--json' => true]);
        self::assertSame(0, $run->getStatusCode(), $run->getErrorOutput());
        $report = $this->json($run);

        self::assertSame(1, $report['schema']);
        self::assertSame(48, $report['threads']);
        self::assertSame(48, $report['cores'], 'no lscpu in the test runner: cores fall back to threads');
        self::assertSame(192512, $report['ram_mb']);
        self::assertSame(2, $report['reserve_threads']);
        self::assertSame(46, $report['allocatable_threads']);
        self::assertSame(24 + 46, $report['committed_threads'], 'an unlimited project counts as allocatable');
        self::assertTrue($report['overcommitted']);
        self::assertSame(['count' => 0, 'max' => 25], $report['instances']);

        $ci = $report['projects'][array_search('ci', array_column($report['projects'], 'name'), true)];
        self::assertSame(24, $ci['cpu_threads']);
        self::assertSame(24, $ci['effective_threads']);
        self::assertSame(3, $ci['slots']);
        self::assertSame(0, $ci['busy']);
        self::assertNull($ci['used_cores']);
        $byName = array_column($ci['templates'], null, 'name');
        self::assertNull($byName['gates']['cpu_threads']);
        self::assertSame(24, $byName['gates']['effective_threads']);
        self::assertSame(2, $byName['gates']['slots']);
        self::assertSame(4, $byName['small']['effective_threads']);
        self::assertNull($byName['small']['slots']);

        $free = $report['projects'][array_search('free', array_column($report['projects'], 'name'), true)];
        self::assertNull($free['cpu_threads']);
        self::assertSame(46, $free['effective_threads']);
    }

    public function testWhatIfOptionsAndBusyAndUsage(): void
    {
        $this->cli('fleet:project:update', ['name' => 'ci', '--cpu-threads' => '24']);
        $this->cli('fleet:template:create', ['project' => 'ci', 'name' => 'gates']);
        $pool = $this->tempHome.'/slots/ci/gates';
        mkdir($pool, 0o775, true);
        touch($pool.'/a.lock');
        $held = fopen($pool.'/a.lock', 'c');
        self::assertNotFalse($held);
        flock($held, \LOCK_EX);

        $cg = $this->tempHome.'/test-cgroup/fleet.slice/fleet-ci.slice/fleet-ci-gates.slice';
        mkdir($cg, 0o775, true);
        $sampler = self::getContainer()->get(UsageSampler::class);
        $now = new \DateTimeImmutable();
        file_put_contents($cg.'/cpu.stat', "usage_usec 0\nnr_throttled 0\n");
        $sampler->sample($now->modify('-10 seconds'));
        file_put_contents($cg.'/cpu.stat', "usage_usec 30000000\nnr_throttled 0\n");
        $sampler->sample($now);

        $report = $this->json($this->cli('fleet:host:capacity', ['--json' => true, '--reserve' => '8', '--max-instances' => '40']));
        fclose($held);

        self::assertSame(40, $report['allocatable_threads']);
        self::assertSame(['count' => 0, 'max' => 40], $report['instances']);
        $ci = $report['projects'][array_search('ci', array_column($report['projects'], 'name'), true)];
        self::assertSame(1, $ci['templates'][0]['busy']);
        self::assertSame(3.0, $ci['templates'][0]['used_cores']);

        $text = $this->cli('fleet:host:capacity');
        self::assertSame(0, $text->getStatusCode());
        self::assertStringContainsString('allocatable 46', $text->getDisplay());
    }

    public function testBadOptionIsUsageError(): void
    {
        self::assertSame(2, $this->cli('fleet:host:capacity', ['--reserve' => 'x'])->getStatusCode());
        self::assertSame(2, $this->cli('fleet:host:capacity', ['--max-instances' => '0'])->getStatusCode());
    }
}
