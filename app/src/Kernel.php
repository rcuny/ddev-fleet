<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\UniqueJobTypesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        ProductionGuard::assertSafe($this->environment, ProductionGuard::currentVars() + ['PROJECT_DIR' => $this->getProjectDir()]);

        parent::boot();
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new UniqueJobTypesPass(), PassConfig::TYPE_OPTIMIZE);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
