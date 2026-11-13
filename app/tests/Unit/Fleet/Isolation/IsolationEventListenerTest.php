<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Domain\Host\IsolationMode;
use App\Fleet\Isolation\IsolationEventListener;
use App\Fleet\Isolation\IsolationEventSource;
use App\Fleet\Isolation\IsolationPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsolationEventListenerTest extends TestCase
{
    /** @var list<IsolationPass> */
    private array $results = [];

    private int $passes = 0;

    /** @var list<int> */
    private array $sleeps = [];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lines(): iterable
    {
        $e = static fn (string $type, string $action, string $name): string => json_encode(['Type' => $type, 'Action' => $action, 'Actor' => ['Attributes' => ['name' => $name]]], \JSON_THROW_ON_ERROR);

        yield 'router start' => [$e('container', 'start', 'ddev-router'), true];
        yield 'web die' => [$e('container', 'die', 'ddev-blog--main-web'), true];
        yield 'foreign container' => [$e('container', 'start', 'postgres'), false];
        yield 'exec die is not die' => [$e('container', 'exec_die', 'ddev-router'), false];
        yield 'shared network connect' => [$e('network', 'connect', 'ddev_default'), true];
        yield 'link network create' => [$e('network', 'create', 'fleet-link-ab12'), true];
        yield 'other network' => [$e('network', 'connect', 'bridge'), false];
        yield 'image event' => [$e('image', 'pull', 'ddev-router'), false];
        yield 'not json' => ['garbage', false];
        yield 'json scalar' => ['"x"', false];
    }

    #[DataProvider('lines')]
    public function testRelevance(string $line, bool $expected): void
    {
        self::assertSame($expected, IsolationEventListener::relevant($line));
    }

    private const ROUTER = '{"Type":"container","Action":"start","Actor":{"Attributes":{"name":"ddev-router"}}}';

    public function testARelevantChunkWaitsTheDebounceThenRunsOnePass(): void
    {
        $listener = $this->listener();

        self::assertTrue($listener->run($this->source([[self::ROUTER."\n".self::ROUTER], ['{"Type":"image"}']]), $this->stopAfter(2)));
        self::assertSame(1, $this->passes);
        self::assertSame([1000], $this->sleeps);
    }

    public function testIrrelevantEventsRunNoPass(): void
    {
        $this->listener()->run($this->source([['{"Type":"container","Action":"start","Actor":{"Attributes":{"name":"other"}}}'], ['']]), $this->stopAfter(2));

        self::assertSame(0, $this->passes);
    }

    public function testABusyPassIsAskedAgainUntilItRuns(): void
    {
        $this->results = [new IsolationPass(IsolationMode::Enforce, busy: true), new IsolationPass(IsolationMode::Enforce, busy: true), new IsolationPass(IsolationMode::Enforce, applied: true)];

        $this->listener()->run($this->source([[self::ROUTER]]), $this->stopAfter(1));

        self::assertSame(3, $this->passes);
        self::assertSame([1000, 500, 500], $this->sleeps);
    }

    public function testAPassThatKeepsBeingBusyGivesUp(): void
    {
        $this->results = array_fill(0, 20, new IsolationPass(IsolationMode::Enforce, busy: true));

        $this->listener()->run($this->source([[self::ROUTER]]), $this->stopAfter(1));

        self::assertSame(IsolationEventListener::BUSY_RETRIES + 1, $this->passes);
    }

    public function testTheStreamIsReopenedUntilStopAndDockerFailureIsReported(): void
    {
        self::assertTrue($this->listener()->run($this->source([[self::ROUTER], ['x'], ['y']]), $this->stopAfter(3)));
        self::assertSame(1, $this->passes);

        self::assertFalse($this->listener()->run($this->source([], false)));
    }

    public function testAPassThatThrowsDoesNotEndTheListener(): void
    {
        $listener = new IsolationEventListener(static function (): IsolationPass {
            throw new \RuntimeException('docker is gone');
        }, sleepMs: static function (int $ms): void {
        });

        self::assertTrue($listener->run($this->source([[self::ROUTER], [self::ROUTER]]), $this->stopAfter(2)));
    }

    /**
     * @return \Closure(): bool true once $windows windows were read
     */
    private function stopAfter(int $windows): \Closure
    {
        $read = 0;

        return static function () use (&$read, $windows): bool {
            return ++$read >= $windows;
        };
    }

    private function listener(): IsolationEventListener
    {
        return new IsolationEventListener(function (): IsolationPass {
            ++$this->passes;

            return array_shift($this->results) ?? new IsolationPass(IsolationMode::Enforce);
        }, sleepMs: function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    /**
     * @param list<array{string}> $windows one entry per call of consume(): the chunks it delivers
     */
    private function source(array $windows, bool $answers = true): IsolationEventSource
    {
        return new class($windows, $answers) implements IsolationEventSource {
            private int $call = 0;

            /**
             * @param list<array{string}> $windows
             */
            public function __construct(private readonly array $windows, private readonly bool $answers)
            {
            }

            public function consume(\Closure $onLines): bool
            {
                foreach ($this->windows[$this->call++] ?? [] as $chunk) {
                    $onLines($chunk);
                }

                return $this->answers;
            }
        };
    }
}
