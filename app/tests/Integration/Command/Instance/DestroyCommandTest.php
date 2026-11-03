<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Tests\Support\InstanceCliTestCase;

final class DestroyCommandTest extends InstanceCliTestCase
{
    public function testSingleIdNeedsNoConfirmation(): void
    {
        $this->instances([InstanceState::Running]);

        $tester = $this->command('fleet:instance:destroy', ['id' => ['acme--i1']]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 succeeded, 0 failed', $tester->getDisplay());
        self::assertSame([], new InstanceRepository($this->em)->ids());
    }

    public function testSelectorWithoutYesIsRefusedNonInteractively(): void
    {
        $this->instances([InstanceState::Running, InstanceState::Running]);

        $tester = $this->command('fleet:instance:destroy', ['--all' => true]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('pass --yes', $tester->getErrorOutput());
        self::assertCount(2, new InstanceRepository($this->em)->ids());

        $several = $this->command('fleet:instance:destroy', ['id' => ['acme--i1', 'acme--i2']]);
        self::assertSame(2, $several->getStatusCode());
        self::assertCount(2, new InstanceRepository($this->em)->ids());
    }

    public function testYesDestroysTheSelection(): void
    {
        $this->instances([InstanceState::Running, InstanceState::Stopped]);

        $tester = $this->command('fleet:instance:destroy', ['--project' => 'acme', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('2 succeeded, 0 failed', $tester->getDisplay());
    }

    public function testTypedCountConfirms(): void
    {
        $this->instances([InstanceState::Running, InstanceState::Running]);

        $tester = $this->command('fleet:instance:destroy', ['--all' => true], ['2'], true);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Type 2 to confirm', $tester->getDisplay());
        self::assertSame([], new InstanceRepository($this->em)->ids());
    }

    public function testWrongConfirmationAborts(): void
    {
        $this->instances([InstanceState::Running, InstanceState::Running]);

        $tester = $this->command('fleet:instance:destroy', ['--all' => true], ['3'], true);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Aborted', $tester->getErrorOutput());
        self::assertCount(2, new InstanceRepository($this->em)->ids());
    }

    public function testUnknownInstanceExitsThree(): void
    {
        $tester = $this->command('fleet:instance:destroy', ['id' => ['acme--nope']]);

        self::assertSame(3, $tester->getStatusCode());
    }
}
