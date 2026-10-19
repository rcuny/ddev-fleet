<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Domain\Instance\InstanceState;
use App\Tests\Support\InstanceCliTestCase;

final class ListCommandTest extends InstanceCliTestCase
{
    public function testTableAndJson(): void
    {
        $this->instances([InstanceState::Running, InstanceState::Stopped]);

        $table = $this->command('fleet:instance:list');
        self::assertSame(0, $table->getStatusCode());
        self::assertStringContainsString('acme--i1', $table->getDisplay());
        self::assertStringContainsString('acme--i2', $table->getDisplay());

        $json = $this->decode($this->command('fleet:instance:list', ['--json' => true, '--state' => 'stopped']));
        self::assertIsArray($json['instances']);
        self::assertCount(1, $json['instances']);
        self::assertSame('acme--i2', $json['instances'][0]['id']);
        self::assertSame('stopped', $json['instances'][0]['state']);

        self::assertSame(3, $this->command('fleet:instance:list', ['--project' => 'nope'])->getStatusCode());
        self::assertSame(1, $this->command('fleet:instance:list', ['--state' => 'sleepy'])->getStatusCode());
    }
}
