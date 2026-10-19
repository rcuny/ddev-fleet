<?php

declare(strict_types=1);

namespace App\Tests\Repo\Support;

use Symfony\Component\Process\Process;

/**
 * Shared plumbing of the Installer*Test classes: runs the bash installer in dry-run (or sources its helpers) with an
 * os-release fixture and stub executables in front of PATH. Nothing here ever runs the installer for real.
 */
trait InstallerHarness
{
    private string $scratchDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->scratchDir && is_dir($this->scratchDir)) {
            new Process(['rm', '-rf', $this->scratchDir])->mustRun();
        }
    }

    private static function productRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    private static function installDir(): string
    {
        return self::productRoot().'/install';
    }

    private function scratch(): string
    {
        if ('' === $this->scratchDir) {
            $this->scratchDir = sys_get_temp_dir().'/installer-test-'.bin2hex(random_bytes(6));
            mkdir($this->scratchDir, 0o700, true);
        }

        return $this->scratchDir;
    }

    /** Path of an os-release file for the dry-run (the real /etc/os-release of the test host must not matter). */
    private function osRelease(string $id = 'debian', string $version = '13'): string
    {
        $path = $this->scratch().'/os-release-'.$id.$version;
        file_put_contents($path, \sprintf("PRETTY_NAME=\"Test\"\nID=%s\nVERSION_ID=\"%s\"\n", $id, $version));

        return $path;
    }

    /**
     * @param array<string, string> $scripts executable name => body (a /bin/sh script)
     *
     * @return string a directory to put in front of PATH
     */
    private function stubs(array $scripts): string
    {
        $dir = $this->scratch().'/stubs-'.bin2hex(random_bytes(3));
        mkdir($dir, 0o700, true);
        foreach ($scripts as $name => $body) {
            file_put_contents($dir.'/'.$name, "#!/bin/sh\n".$body."\n");
            chmod($dir.'/'.$name, 0o755);
        }

        return $dir;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function bash(array $args, array $env = [], ?string $stdin = null, ?string $stubDir = null): array
    {
        if (null !== $stubDir) {
            $env['PATH'] = $stubDir.':'.(string) getenv('PATH');
        }
        $process = new Process(['bash', ...$args], self::productRoot(), $env, $stdin, 120);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /**
     * install.sh --dry-run from a checkout, on a pretend Debian 13.
     *
     * @param list<string>          $args extra flags (default: --domain and --skip-dns-check)
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function dryRun(?array $args = null, array $env = [], ?string $stubDir = null): array
    {
        $args ??= ['--domain=fleet.example.com', '--skip-dns-check'];
        $env += ['FLEET_OS_RELEASE' => $this->osRelease()];

        return $this->bash([self::installDir().'/install.sh', '--dry-run', ...$args], $env, null, $stubDir);
    }

    /**
     * Runs shell code with install/lib/common.sh sourced (errexit, nounset and pipefail on, like the installer).
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function withCommon(string $code, array $env = [], ?string $stubDir = null): array
    {
        return $this->bash(
            ['-c', 'set -Eeuo pipefail; INSTALL_DIR='.escapeshellarg(self::installDir()).'; . "$INSTALL_DIR/lib/common.sh"; '.$code],
            $env,
            null,
            $stubDir,
        );
    }

    /** @return list<string> every file of install/ that is a shell script (by extension or bash shebang) */
    private static function shellScripts(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::installDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $head = (string) fgets(fopen($path, 'r') ?: throw new \RuntimeException($path), 200);
            if (str_ends_with($path, '.sh') || str_starts_with($head, '#!/usr/bin/env bash') || str_starts_with($head, '#!/bin/bash')) {
                $found[] = $path;
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<string> every regular file under install/ (templates and scripts alike) */
    private static function installFiles(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::installDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The non-comment lines of a file, with their 1-based numbers.
     *
     * @return array<int, string>
     */
    private static function codeLines(string $path): array
    {
        $lines = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (1 === preg_match('/^\s*#/', $line)) {
                continue;
            }
            $lines[$index + 1] = $line;
        }

        return $lines;
    }

    /** @return list<string> the step files in numeric order, as the installer discovers them */
    private static function stepNames(): array
    {
        $names = array_map(static fn (string $p): string => basename($p, '.sh'), glob(self::installDir().'/steps/[0-9][0-9]-*.sh') ?: []);
        sort($names);

        return $names;
    }
}
