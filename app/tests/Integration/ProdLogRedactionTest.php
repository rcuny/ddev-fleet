<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Tests\Support\TempFleetHome;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * The setup link is a 24-hour credential in a query string, and Symfony's RouterListener logs the whole request URI.
 * In prod the log is `fingers_crossed`: an error-level record anywhere in the request flushes everything, URI included,
 * to the journal. Boots the real production kernel, with its stderr handlers pointed at a file, and proves the token
 * never reaches a handler, whichever channel logs it.
 */
final class ProdLogRedactionTest extends TestCase
{
    use TempFleetHome;

    private const string TOKEN = 'T0kenValueThatMustNeverReachTheJournal';

    private string $cacheDir = '';
    private string $logFile = '';
    private ?Kernel $kernel = null;
    private ?string $previousSecret = null;
    private ?string $previousRunner = null;

    protected function setUp(): void
    {
        $this->useFreshFleetHome();
        $this->previousSecret = isset($_SERVER['APP_SECRET']) && \is_string($_SERVER['APP_SECRET']) ? $_SERVER['APP_SECRET'] : null;
        self::setEnv('APP_SECRET', 'prod-log-test-secret');
        $this->previousRunner = isset($_SERVER['FLEET_RUNNER']) && \is_string($_SERVER['FLEET_RUNNER']) ? $_SERVER['FLEET_RUNNER'] : null;
        self::setEnv('FLEET_RUNNER', 'process');

        $this->cacheDir = sys_get_temp_dir().'/fleet-prod-log-cache-'.bin2hex(random_bytes(6));
        $this->logFile = sys_get_temp_dir().'/fleet-prod-log-'.bin2hex(random_bytes(6)).'.log';
        $cacheDir = $this->cacheDir;
        $logFile = $this->logFile;
        $this->kernel = new class('prod', false, $cacheDir, $logFile) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $cache, private readonly string $log)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->cache;
            }

            protected function build(ContainerBuilder $container): void
            {
                $log = $this->log;
                $container->addCompilerPass(new class($log) implements CompilerPassInterface {
                    public function __construct(private readonly string $log)
                    {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        // The loggers are private; the test logs through them directly.
                        foreach ($container->getDefinitions() as $id => $definition) {
                            if (str_starts_with($id, 'monolog.logger')) {
                                $definition->setPublic(true);
                            }
                        }
                        $container->getAlias('logger')->setPublic(true);
                        foreach ($container->getDefinitions() as $definition) {
                            if (is_a((string) $definition->getClass(), StreamHandler::class, true) && 'php://stderr' === $definition->getArgument(0)) {
                                $definition->replaceArgument(0, $this->log);
                            }
                        }
                    }
                });
            }
        };
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove([$this->cacheDir, $this->logFile]);
        self::setEnv('APP_SECRET', $this->previousSecret);
        self::setEnv('FLEET_RUNNER', $this->previousRunner);
        $this->restoreFleetHome();
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): string
    {
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => $command, '--no-interaction' => true] + $input), $output);
        $text = $output->fetch();
        self::assertSame(0, $status, $text);

        return $text;
    }

    /**
     * A migrated database, an initialised host and a real setup token, as the installer leaves them.
     */
    private function prepareSetup(): string
    {
        $this->console('doctrine:migrations:migrate');
        $this->console('fleet:host:init', ['--domain' => 'fleet.example.com']);
        $url = $this->console('fleet:setup:token');
        self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $url, $match), 'the setup URL carries the token');
        // Whatever the preparation logged is not the subject of the test.
        file_put_contents($this->logFile, '');

        return $match[1];
    }

    private function written(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    public function testTheSetupTokenOfARoutedRequestNeverReachesTheLogWhenAnErrorFlushesTheBuffer(): void
    {
        self::assertNotNull($this->kernel);
        $token = $this->prepareSetup();
        $response = $this->kernel->handle(Request::create('https://fleet.example.com/setup?token='.$token, 'GET', [], [], [], ['HTTPS' => 'on', 'REMOTE_ADDR' => '10.1.2.3']));
        self::assertSame(302, $response->getStatusCode(), 'the exchange worked, so the token was valid');

        $logger = $this->kernel->getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->error('Something else failed in the same request');

        $log = $this->written();
        self::assertStringContainsString('Something else failed in the same request', $log, 'the buffer was flushed');
        self::assertStringContainsString('Matched route', $log, 'RouterListener\'s record is part of the flush: the redaction is what protects the token');
        self::assertStringNotContainsString($token, $log);
        self::assertStringContainsString('token=[redacted]', $log);
    }

    public function testEveryChannelIsCovered(): void
    {
        self::assertNotNull($this->kernel);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        foreach (['logger', 'monolog.logger.request', 'monolog.logger.security', 'monolog.logger.php', 'monolog.logger.doctrine'] as $id) {
            if (!$container->has($id)) {
                continue;
            }
            $logger = $container->get($id);
            self::assertInstanceOf(LoggerInterface::class, $logger);
            $logger->warning('GET /setup?TOKEN='.self::TOKEN, ['request_uri' => 'https://localhost/setup?token='.self::TOKEN]);
        }
        $container->get('logger')->critical('flush');

        $log = $this->written();
        self::assertStringContainsString('flush', $log);
        self::assertStringNotContainsString(self::TOKEN, $log);
    }
}
