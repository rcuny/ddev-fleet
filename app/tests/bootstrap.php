<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}

// Every PHPUnit process gets its own FLEET_HOME so tests never touch real state.
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
