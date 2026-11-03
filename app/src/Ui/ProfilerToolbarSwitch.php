<?php

declare(strict_types=1);

namespace App\Ui;

use Symfony\Bundle\WebProfilerBundle\EventListener\WebDebugToolbarListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dev only: FLEET_PROFILER_TOOLBAR=0 hides the Symfony toolbar (it is on by default). The browser E2E suite
 * sets it because the toolbar would cover the phone bottom bar. The bundle reads its own "toolbar" option at
 * container build time, so an environment variable cannot be wired there; the mode is switched at request time.
 * The same switch is available per request with the header "X-Fleet-Profiler-Toolbar: 0", for suites that run
 * against a server whose environment they do not control (the companion's php-fpm).
 */
#[When(env: 'dev')]
final readonly class ProfilerToolbarSwitch
{
    public function __construct(
        #[Autowire(service: 'web_profiler.debug_toolbar')]
        private WebDebugToolbarListener $toolbar,
        #[Autowire(env: 'bool:FLEET_PROFILER_TOOLBAR')]
        private bool $enabled,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$this->enabled || '0' === $event->getRequest()->headers->get('X-Fleet-Profiler-Toolbar')) {
            $this->toolbar->setMode(WebDebugToolbarListener::DISABLED);
        }
    }
}
