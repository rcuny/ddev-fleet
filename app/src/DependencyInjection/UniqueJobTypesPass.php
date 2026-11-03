<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Fleet\Jobs\JobType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails the container build when two job types share a name, instead of the first use of JobTypeRegistry. The name is
 * read from an instance made without its constructor, so JobType::name() must return a constant. A name that depends on
 * constructor state would be mis-keyed here, so one that throws on such an instance, or is not a lower-case word, fails
 * the build too.
 */
final class UniqueJobTypesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $seen = [];
        foreach (array_keys($container->findTaggedServiceIds('fleet.job_type')) as $id) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            if ($definition->isAbstract() || !\is_string($class) || !is_subclass_of($class, JobType::class)) {
                continue;
            }

            $name = self::constantName($class);
            if (isset($seen[$name])) {
                throw new \LogicException(\sprintf('The job type "%s" is registered twice (%s and %s).', $name, $seen[$name], $class));
            }
            $seen[$name] = $class;
        }
    }

    /**
     * @param class-string<JobType> $class
     */
    private static function constantName(string $class): string
    {
        try {
            $name = new \ReflectionClass($class)->newInstanceWithoutConstructor()->name();
        } catch (\Throwable $e) {
            throw new \LogicException(\sprintf('%s::name() must return a constant: it fails on an instance made without its constructor.', $class), 0, $e);
        }
        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new \LogicException(\sprintf('%s::name() must return a constant lower-case word, got "%s".', $class, $name));
        }

        return $name;
    }
}
