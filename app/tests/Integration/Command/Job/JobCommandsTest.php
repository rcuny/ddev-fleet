<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Job;

use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceCliTestCase;

final class JobCommandsTest extends InstanceCliTestCase
{
    public function testListFilters(): void
    {
        $this->instances([InstanceState::Stopped, InstanceState::Stopped]);
        $this->runner()->expect(['ddev', 'start'], new CommandResult(1, '', 'boom'));
        $this->command('fleet:instance:start', ['--all' => true]);

        $all = $this->decode($this->command('fleet:job:list', ['--json' => true]));
        self::assertIsArray($all['jobs']);
        self::assertCount(2, $all['jobs']);

        $failed = $this->decode($this->command('fleet:job:list', ['--json' => true, '--status' => 'failed']));
        self::assertIsArray($failed['jobs']);
        self::assertCount(1, $failed['jobs']);
        self::assertSame('failed', $failed['jobs'][0]['status']);

        $one = $this->decode($this->command('fleet:job:list', ['--json' => true, '--instance' => 'acme--i2']));
        self::assertIsArray($one['jobs']);
        self::assertCount(1, $one['jobs']);
        self::assertSame('acme--i2', $one['jobs'][0]['instance']);

        self::assertCount(1, $this->decode($this->command('fleet:job:list', ['--json' => true, '--limit' => '1']))['jobs']);
        self::assertCount(0, $this->decode($this->command('fleet:job:list', ['--json' => true, '--trigger' => 'ui']))['jobs']);
        self::assertSame(1, $this->command('fleet:job:list', ['--status' => 'weird'])->getStatusCode());
        self::assertStringContainsString('acme--i1', $this->command('fleet:job:list')->getDisplay());
    }

    public function testShowIncludesTriggerAndRequester(): void
    {
        $this->instances([InstanceState::Stopped]);
        $this->command('fleet:instance:start', ['id' => ['acme--i1']]);

        $table = $this->command('fleet:job:show', ['id' => '1']);
        self::assertSame(0, $table->getStatusCode(), $table->getDisplay());
        self::assertStringContainsString('trigger: cli', $table->getDisplay());
        self::assertMatchesRegularExpression('/requestedBy: cli:\S+/', $table->getDisplay());

        $json = $this->decode($this->command('fleet:job:show', ['id' => '1', '--json' => true]));
        self::assertIsArray($json['job']);
        self::assertSame('cli', $json['job']['trigger']);
        self::assertSame('succeeded', $json['job']['status']);

        self::assertSame(3, $this->command('fleet:job:show', ['id' => '99'])->getStatusCode());
    }

    public function testRetryCreatesANewJob(): void
    {
        $this->instances([InstanceState::Stopped]);
        $this->runner()->expect(['ddev', 'start'], new CommandResult(1, '', 'boom'));
        $this->command('fleet:instance:start', ['id' => ['acme--i1']]);

        $tester = $this->command('fleet:job:retry', ['id' => '1']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 succeeded, 0 failed', $tester->getDisplay());
        $this->em->clear();
        $jobs = self::getContainer()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);
        $second = $jobs->find(2);
        self::assertNotNull($second);
        self::assertSame(1, $second->retryOf());
        self::assertSame('start', $second->type());

        // Only a failed or interrupted job can be retried.
        self::assertSame(2, $this->command('fleet:job:retry', ['id' => '2'])->getStatusCode());
        self::assertSame(3, $this->command('fleet:job:retry', ['id' => '77'])->getStatusCode());
    }
}
