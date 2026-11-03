<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Fleet\Instances\AssetsSeedStep;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\DeployWorkflow;
use App\Fleet\Instances\StepPhase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeployStepRegistrationTest extends KernelTestCase
{
    /**
     * @return list<DeployStep>
     */
    private function registered(): array
    {
        $workflow = self::getContainer()->get(DeployWorkflow::class);
        self::assertInstanceOf(DeployWorkflow::class, $workflow);
        $steps = new \ReflectionProperty($workflow, 'steps')->getValue($workflow);
        self::assertIsIterable($steps);

        return iterator_to_array($steps, false);
    }

    public function testTheSeedStepIsRegisteredOnceAndBeforeStart(): void
    {
        $seeds = array_values(array_filter($this->registered(), static fn (DeployStep $s): bool => $s instanceof AssetsSeedStep));

        self::assertCount(1, $seeds);
        self::assertSame(StepPhase::BeforeStart, $seeds[0]->phase());
    }

    public function testEveryDeployStepClassIsTagged(): void
    {
        $registered = array_map(static fn (DeployStep $s): string => $s::class, $this->registered());
        $declared = [];
        foreach (glob(\dirname(__DIR__, 4).'/src/Fleet/**/*.php', \GLOB_BRACE) ?: [] as $file) {
            $class = 'App\\'.str_replace('/', '\\', substr($file, \strlen(\dirname(__DIR__, 4).'/src/'), -4));
            if (class_exists($class) && is_subclass_of($class, DeployStep::class) && !new \ReflectionClass($class)->isAbstract()) {
                $declared[] = $class;
            }
        }

        self::assertNotEmpty($declared);
        self::assertEqualsCanonicalizing($declared, array_values(array_unique($registered)), 'every DeployStep must carry #[AutoconfigureTag(\'fleet.deploy_step\', [\'priority\' => N])]');
    }
}
