<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Fleet\Instances\InstanceIsolationWriter;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-297: the db container leaves ddev_default; an instance without one pins "db" to loopback.
 */
final class InstanceIsolationTest extends InstanceJobTestCase
{
    private function write(string $composeConfig): string
    {
        $this->runner->expect(['ddev', 'debug'], new CommandResult(0, $composeConfig));
        $instance = $this->instance('main', 'develop');
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.ddev', 0o750, true);
        $job = $this->job('deploy', $instance);
        new InstanceIsolationWriter($this->ddev)->write($dir, $this->logFor($job));

        return (string) file_get_contents($dir.'/'.InstanceIsolationWriter::RELATIVE_PATH);
    }

    public function testTheDbServiceKeepsOnlyTheProjectNetwork(): void
    {
        $text = $this->write("services:\n  db:\n    image: db\n  web:\n    image: web\n");

        self::assertStringContainsString('!override', $text);
        self::assertStringContainsString('default', $text);
        self::assertStringNotContainsString('ddev_default', $text);
        self::assertStringNotContainsString('#ddev-generated', $text);
        self::assertStringNotContainsString('extra_hosts', $text);
    }

    public function testAnInstanceWithoutDbPinsTheNameToLoopback(): void
    {
        $text = $this->write("services:\n  web:\n    image: web\n");
        $parsed = Yaml::parse($text);

        self::assertSame(['db:127.0.0.1'], $parsed['services']['web']['extra_hosts']);
        self::assertArrayNotHasKey('db', $parsed['services']);
    }
}
