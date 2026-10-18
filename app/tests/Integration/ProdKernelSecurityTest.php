<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Security\StandIn\StandInAuthenticator;
use App\Security\StandIn\StandInController;
use App\Security\StandIn\StandInSettings;
use App\Tests\Support\TempFleetHome;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the real production kernel: the dev/test stand-in must not exist there at all.
 */
final class ProdKernelSecurityTest extends TestCase
{
    use TempFleetHome;

    private string $cacheDir = '';
    private ?Kernel $kernel = null;
    private ?string $previousSecret = null;

    protected function setUp(): void
    {
        $this->useFreshFleetHome();
        // Dotenv fills $_SERVER/$_ENV but not getenv(), so restore from $_SERVER.
        $this->previousSecret = isset($_SERVER['APP_SECRET']) && \is_string($_SERVER['APP_SECRET']) ? $_SERVER['APP_SECRET'] : null;
        self::setEnv('APP_SECRET', 'prod-kernel-test-secret');

        $this->cacheDir = sys_get_temp_dir().'/fleet-prod-cache-'.bin2hex(random_bytes(6));
        $cacheDir = $this->cacheDir;
        $this->kernel = new class('prod', false, $cacheDir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $cache)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->cache;
            }

            protected function build(ContainerBuilder $container): void
            {
                // The prod logger writes to php://stderr; keep the test output clean.
                $container->addCompilerPass(new class implements CompilerPassInterface {
                    public function process(ContainerBuilder $container): void
                    {
                        foreach ($container->getDefinitions() as $definition) {
                            if (is_a((string) $definition->getClass(), StreamHandler::class, true) && 'php://stderr' === $definition->getArgument(0)) {
                                $definition->replaceArgument(0, '/dev/null');
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
        new Filesystem()->remove($this->cacheDir);
        self::setEnv('APP_SECRET', $this->previousSecret);
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
     * @param array<string, string> $server
     */
    private function handle(string $path, array $server = []): Response
    {
        self::assertNotNull($this->kernel);

        return $this->kernel->handle(Request::create($path, 'GET', [], [], [], $server));
    }

    public function testTheStandInHeaderDoesNotAuthenticate(): void
    {
        self::assertSame(401, $this->handle('/', ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin'])->getStatusCode());
    }

    public function testTheStandInCookieDoesNotAuthenticate(): void
    {
        self::assertNotNull($this->kernel);
        $request = Request::create('/', 'GET', [], ['fleet_stand_in_role' => 'admin']);

        self::assertSame(401, $this->kernel->handle($request)->getStatusCode());
    }

    public function testStandInClassesAreAbsentFromTheCompiledProdContainer(): void
    {
        self::assertNotNull($this->kernel);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        foreach ([StandInAuthenticator::class, StandInController::class, StandInSettings::class] as $class) {
            self::assertFalse($container->has($class), $class);
        }

        // Private services are invisible to has(), so also scan the compiled container for the classes.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            // removed-ids.php and *.meta* merely list removed/known ids and resources.
            if (!$file->isFile() || 'removed-ids.php' === $file->getFilename() || str_contains($file->getFilename(), '.meta')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'StandIn')) {
                $offenders[] = substr($file->getPathname(), \strlen($this->cacheDir));
            }
        }
        self::assertSame([], $offenders, 'the prod container must not reference the stand-in');
    }

    public function testTheSwitchRouteDoesNotExist(): void
    {
        self::assertSame(404, $this->handle('/_dev/stand-in/admin')->getStatusCode());
    }

    public function testRemoteUserFromAnUntrustedPeerIs401(): void
    {
        $response = $this->handle('/', ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_REMOTE_USER' => 'alice', 'HTTP_REMOTE_GROUPS' => 'fleet-admins']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRemoteUserFromALoopbackPeerAuthenticates(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_REMOTE_USER' => 'alice', 'HTTP_REMOTE_GROUPS' => 'fleet-admins'];

        // Authorised as admin; the route simply does not exist.
        self::assertSame(404, $this->handle('/admin/x', $server)->getStatusCode());
        self::assertSame(200, $this->handle('/', $server)->getStatusCode());
    }

    public function testAMemberIsNotAnAdmin(): void
    {
        $server = ['REMOTE_ADDR' => '::1', 'HTTP_REMOTE_USER' => 'bob', 'HTTP_REMOTE_GROUPS' => 'demo'];

        self::assertSame(403, $this->handle('/admin/x', $server)->getStatusCode());
    }

    public function testHealthIsPublic(): void
    {
        $response = $this->handle('/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
