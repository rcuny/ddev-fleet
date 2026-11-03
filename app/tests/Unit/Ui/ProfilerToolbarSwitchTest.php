<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui;

use App\Ui\ProfilerToolbarSwitch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\WebProfilerBundle\EventListener\WebDebugToolbarListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ProfilerToolbarSwitchTest extends TestCase
{
    #[DataProvider('flags')]
    public function testFlagDecidesWhetherTheToolbarStaysOn(bool $enabled, bool $expectedOn): void
    {
        $toolbar = new WebDebugToolbarListener(new Environment(new ArrayLoader()));
        $kernel = $this->createStub(HttpKernelInterface::class);

        (new ProfilerToolbarSwitch($toolbar, $enabled))(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($expectedOn, $toolbar->isEnabled());
    }

    public function testHeaderHidesTheToolbarForOneRequest(): void
    {
        $toolbar = new WebDebugToolbarListener(new Environment(new ArrayLoader()));
        $request = new Request();
        $request->headers->set('X-Fleet-Profiler-Toolbar', '0');

        (new ProfilerToolbarSwitch($toolbar, true))(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($toolbar->isEnabled());
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function flags(): iterable
    {
        yield 'default keeps the toolbar' => [true, true];
        yield 'FLEET_PROFILER_TOOLBAR=0 hides it' => [false, false];
    }
}
