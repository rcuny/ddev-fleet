<?php

declare(strict_types=1);

use App\Fleet\FleetPaths;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// An inherited APP_ENV (e.g. the companion container exports dev) must never select the environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}

// Every PHPUnit process gets its own FLEET_HOME so tests never touch real state; this overrides
// any FLEET_HOME inherited from the shell (the companion container exports one).
$fleetHome = sys_get_temp_dir().'/fleet-test-'.getmypid();
$removeTree = static function (string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    @chmod($dir, 0o700);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    // Make every directory traversable first so read-only trees (chmod'ed by a test) can be removed.
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @chmod($item->getPathname(), 0o700);
        }
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
};

// A previous process with a recycled pid may have left its directory behind.
$removeTree($fleetHome);
mkdir($fleetHome, 0o700, true);

$_SERVER['FLEET_HOME'] = $_ENV['FLEET_HOME'] = $fleetHome;
putenv('FLEET_HOME='.$fleetHome);

register_shutdown_function(static function () use ($fleetHome, $removeTree): void {
    $removeTree($fleetHome);
});

// Build the process database with the real migrations once, so any test that renders a page backed by the project
// tables works without setting up its own database (tests that need isolation still use FleetDatabase).
(static function (): void {
    $kernel = new Kernel('test', true);
    $kernel->boot();

    try {
        $kernel->getContainer()->get(FleetPaths::class)->ensureLayout();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $exit = $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output);
        if (0 !== $exit) {
            fwrite(\STDERR, "tests/bootstrap.php: migrating the test database failed:\n".$output->fetch());
            exit(1);
        }
    } finally {
        $kernel->shutdown();
    }
})();
