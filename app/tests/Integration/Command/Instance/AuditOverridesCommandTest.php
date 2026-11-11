<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Command\Instance\AuditOverridesCommand;
use App\Fleet\Isolation\ComposeOverrideGuard;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AuditOverridesCommandTest extends InstanceJobTestCase
{
    public function testItListsAnInstanceWhoseOverrideWouldNowBeRefusedAndChangesNothing(): void
    {
        $this->instance('main', 'develop');
        $dir = $this->paths->instanceDir('acme--main');
        mkdir($dir.'/.ddev', 0o777, true);
        $file = $dir.'/.ddev/docker-compose.dockersock.yaml';
        file_put_contents($file, "services:\n  web:\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n");

        $tester = new CommandTester(new AuditOverridesCommand($this->instances, new ComposeOverrideGuard(), $this->paths));
        $status = $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $status);
        self::assertSame('acme--main', $report['instances'][0]['instance']);
        self::assertFalse($report['instances'][0]['allowed']);
        self::assertSame('services.web.volumes[0]', $report['instances'][0]['violations'][0]['key']);
        self::assertFileExists($file);

        unlink($file);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No instance has overrides', $tester->getDisplay());
    }
}
