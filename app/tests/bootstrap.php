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
if (!is_dir($fleetHome)) {
    mkdir($fleetHome, 0o700, true);
}

$_SERVER['FLEET_HOME'] = $_ENV['FLEET_HOME'] = $fleetHome;
putenv('FLEET_HOME='.$fleetHome);

register_shutdown_function(static function () use ($fleetHome): void {
    if (!is_dir($fleetHome)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fleetHome, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($fleetHome);
});
