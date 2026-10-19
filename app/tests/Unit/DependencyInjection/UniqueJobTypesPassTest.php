<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection;

use App\DependencyInjection\UniqueJobTypesPass;
use App\Domain\Job\Job;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class UniqueJobTypesPassTest extends TestCase
{
    public function testDistinctNamesPass(): void
    {
        $container = new ContainerBuilder();
        $container->register('a', DeployTypeA::class)->addTag('fleet.job_type');
        $container->register('b', StartType::class)->addTag('fleet.job_type');

        new UniqueJobTypesPass()->process($container);

        $this->addToAssertionCount(1);
    }

    public function testTwoTypesWithTheSameNameFailTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register('a', DeployTypeA::class)->addTag('fleet.job_type');
        $container->register('b', DeployTypeB::class)->addTag('fleet.job_type');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"deploy" is registered twice');
        new UniqueJobTypesPass()->process($container);
    }
}

abstract class FixedType implements JobType
{
    public function __construct(public readonly object $dependency)
    {
    }

    public function execute(Job $job, JobLog $log): void
    {
    }
}

final class DeployTypeA extends FixedType
{
    public function name(): string
    {
        return 'deploy';
    }
}

final class DeployTypeB extends FixedType
{
    public function name(): string
    {
        return 'deploy';
    }
}

final class StartType extends FixedType
{
    public function name(): string
    {
        return 'start';
    }
}
