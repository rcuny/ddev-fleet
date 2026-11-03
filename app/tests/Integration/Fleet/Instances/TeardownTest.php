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

    public function testReadOnlyDirectoriesAreRemoved(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        // Drupal hardens sites/default to 0555 and its files to 0444.
        mkdir($dir.'/docroot/sites/default/nested', 0o755, true);
        file_put_contents($dir.'/docroot/sites/default/settings.php', 'x');
        file_put_contents($dir.'/docroot/sites/default/nested/file', 'x');
        chmod($dir.'/docroot/sites/default/settings.php', 0o444);
        chmod($dir.'/docroot/sites/default/nested', 0o555);
        chmod($dir.'/docroot/sites/default', 0o555);
        $outside = $this->paths->home().'/outside-ro';
        mkdir($outside, 0o755);
        chmod($outside, 0o555);
        symlink($outside, $dir.'/docroot/sites/default-link');
        $job = $this->job('destroy', $instance);

        try {
            $this->teardown->removeCheckout($instance, $this->logFor($job));

            self::assertDirectoryDoesNotExist($dir);
            self::assertSame(0o555, fileperms($outside) & 0o777, 'a symlinked directory is never chmodded');
        } finally {
            chmod($outside, 0o755);
        }
    }

    public function testLeftoverThatCannotBeRemovedFailsWithTheSudoRmLine(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can remove a read-only directory.');
        }
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        file_put_contents($dir.'/file', 'x');
        $parent = \dirname($dir);
        $job = $this->job('destroy', $instance);
        chmod($parent, 0o555);

        try {
            $this->teardown->removeCheckout($instance, $this->logFor($job));
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('sudo rm -rf '.escapeshellarg($dir), $e->getMessage());
            self::assertSame(3, substr_count($this->logText($job), 'Could not remove'));
        } finally {
            chmod($parent, 0o755);
        }
    }

    public function testMissingDirectoryIsSuccess(): void
    {
        $instance = $this->instance();
        $job = $this->job('destroy', $instance);

        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertSame([], $this->runner->callsMatching(['ddev']), 'nothing to delete, so ddev is not asked');
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
