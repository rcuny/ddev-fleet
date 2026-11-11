<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Command\Instance\InstanceIsolateCommand;
use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\InstanceDbIsolationWriter;
use App\Fleet\Isolation\ComposeOverrideGuard;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Workspace\ClaudeTokenApplier;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstanceIsolateCommandTest extends InstanceJobTestCase
{
    private function tester(): CommandTester
    {
        $applier = self::getContainer()->get(ClaudeTokenApplier::class);
        self::assertInstanceOf(ClaudeTokenApplier::class, $applier);

        // Every project looks exposed to the live check: one db container on ddev_default (the fake ignores the filter).
        // The fake answers each expectation once, so queue one pair per running instance.
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running === $instance->state()) {
                $this->runner->expect(['docker', 'ps'], new CommandResult(0, "ddev-x-db|db|com.ddev.site-name=x\n"));
                $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
            }
        }

        return new CommandTester(new InstanceIsolateCommand($this->instances, $applier, $this->paths, new ComposeOverrideGuard(), new InstanceDbIsolationWriter($this->ddev)));
    }

    private function running(string $label, bool $refused = false, InstanceState $state = InstanceState::Running): string
    {
        $instance = $this->instance($label, 'develop', $state);
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.ddev', 0o777, true);
        if ($refused) {
            file_put_contents($dir.'/.ddev/docker-compose.dockersock.yaml', "services:\n  web:\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n");
        }

        return $instance->id();
    }

    private function queued(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM job WHERE type = 'restart'");
    }

    public function testApplySkipsAndReportsInstancesTheDeployGuardRefuses(): void
    {
        $this->running('ok');
        $bad = $this->running('sock', true);

        $tester = $this->tester();
        $status = $tester->execute(['--apply' => true, '--yes' => true], ['interactive' => false]);

        self::assertSame(0, $status);
        self::assertSame(1, $this->queued());
        $out = $tester->getDisplay();
        self::assertStringContainsString($bad.': the deploy guard refuses', $out);
        self::assertStringContainsString('Skipped 1 instance(s)', $out);
        self::assertStringContainsString('Queued 1 restart job(s)', $out);
    }

    public function testSelectorsNarrowTheInstancesByIdOrProject(): void
    {
        $a = $this->running('a');
        $this->running('b');

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['selectors' => [$a], '--apply' => true, '--yes' => true]));
        self::assertSame(1, $this->queued());
        self::assertStringContainsString('with a container on the shared ddev network', $tester->getDisplay());
    }

    public function testAnUnknownSelectorFailsWithoutQueueingAnything(): void
    {
        $this->running('a');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['selectors' => ['nope'], '--apply' => true, '--yes' => true]));
        self::assertSame(0, $this->queued());
        self::assertStringContainsString('No instance or project named "nope"', $tester->getDisplay());
    }

    public function testForceRefusedNeedsAnInteractiveConfirmationAndYesDoesNotReplaceIt(): void
    {
        $this->running('sock', true);

        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['--apply' => true, '--force-refused' => true, '--yes' => true], ['interactive' => false]));
        self::assertSame(0, $this->queued());

        $tester = $this->tester(); // fresh live-check answers for the second run
        $tester->setInputs(['yes', 'yes']);
        self::assertSame(0, $tester->execute(['--apply' => true, '--force-refused' => true]));
        self::assertSame(1, $this->queued());
    }

    public function testAProjectThatAllowsPrivilegedOverridesIsNotSkipped(): void
    {
        $this->running('sock', true);
        $this->project->changeAllowPrivilegedOverrides(true);
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--apply' => true, '--yes' => true], ['interactive' => false]));
        self::assertSame(1, $this->queued());
        self::assertStringNotContainsString('the deploy guard refuses', $tester->getDisplay());
    }

    public function testAStoppedRefusedInstanceIsNeitherQueuedNorReported(): void
    {
        $this->running('ok');
        $this->running('sock', true, InstanceState::Stopped);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--apply' => true, '--yes' => true], ['interactive' => false]));
        self::assertSame(1, $this->queued());
        self::assertStringNotContainsString('the deploy guard refuses', $tester->getDisplay());
    }
}
