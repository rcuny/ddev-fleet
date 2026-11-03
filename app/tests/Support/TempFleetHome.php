<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Points FLEET_HOME at a fresh directory for one test and removes it afterwards.
 */
trait TempFleetHome
{
    private string $tempHome = '';
    private string|false $previousHome = false;

    private function useFreshFleetHome(): string
    {
        $this->previousHome = getenv('FLEET_HOME');
        $this->tempHome = sys_get_temp_dir().'/fleet-home-'.bin2hex(random_bytes(6));
        self::setHomeEnv($this->tempHome);

        return $this->tempHome;
    }

    private function restoreFleetHome(): void
    {
        if (false !== $this->previousHome) {
            self::setHomeEnv($this->previousHome);
        }
        if ('' !== $this->tempHome && is_dir($this->tempHome)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempHome, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tempHome);
        }
        $this->tempHome = '';
    }

    private static function setHomeEnv(string $home): void
    {
        $_SERVER['FLEET_HOME'] = $_ENV['FLEET_HOME'] = $home;
        putenv('FLEET_HOME='.$home);
    }
}
