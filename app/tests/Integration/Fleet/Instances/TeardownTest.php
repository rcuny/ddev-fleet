<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Fleet\Jobs\JobFailed;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

final class TeardownTest extends InstanceJobTestCase
{
    public function testToleratesDdevDeleteFailure(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        $job = $this->job('destroy', $instance);
        $this->runner->expect(['ddev', 'delete'], new CommandResult(1, '', "no such project\n"));
        $this->runner->expect(['ddev', 'delete'], new CommandResult(1, '', "no such project\n"));

        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertDirectoryDoesNotExist($dir);
        self::assertStringContainsString('ddev delete failed with exit code 1; continuing', $this->logText($job));
    }

    public function testRemovesTheTreeWithRetries(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        mkdir($dir.'/a/b', 0o750, true);
        file_put_contents($dir.'/a/b/file', 'x');
        $outside = $this->paths->home().'/outside';
        mkdir($outside);
        file_put_contents($outside.'/keep', 'x');
        symlink($outside, $dir.'/link');
        $job = $this->job('destroy', $instance);

        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertDirectoryDoesNotExist($dir);
        self::assertFileExists($outside.'/keep', 'a symlink is removed, never followed');
        self::assertSame(['ddev delete'], $this->commandNames());
    }

    public function testRootOwnedLeftoverFailsWithTheSudoRmLine(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can remove a read-only directory.');
        }
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        mkdir($dir.'/locked', 0o750);
        file_put_contents($dir.'/locked/file', 'x');
        chmod($dir.'/locked', 0o555);
        $job = $this->job('destroy', $instance);

        try {
            $this->teardown->removeCheckout($instance, $this->logFor($job));
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('sudo rm -rf '.escapeshellarg($dir), $e->getMessage());
            self::assertSame(3, substr_count($this->logText($job), 'Could not remove'));
        } finally {
            chmod($dir.'/locked', 0o755);
        }
    }

    public function testMissingDirectoryIsSuccess(): void
    {
        $instance = $this->instance();
        $job = $this->job('destroy', $instance);

        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertSame([], $this->runner->calls(), 'nothing to delete, so ddev is not asked');
    }

    public function testRemoveSiteDeletesTheSnippet(): void
    {
        $instance = $this->instance();
        $this->sites->write($instance, $this->project);
        self::assertFileExists($this->paths->instanceSiteFile($instance->id()));

        $this->teardown->removeSite($instance);

        self::assertFileDoesNotExist($this->paths->instanceSiteFile($instance->id()));
    }
}
