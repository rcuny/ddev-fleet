<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Port;

use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PortFirewallCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testWithoutPortsInUseListsOnlyWebPorts(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);

        $json = $this->cli('fleet:port:firewall', ['--json' => true]);

        self::assertSame(0, $json->getStatusCode(), $json->getErrorOutput());
        self::assertSame('', $json->getErrorOutput());
        self::assertSame(['ports' => [
            ['port' => 80, 'purpose' => 'http (certificate challenge and redirect to https)', 'projects' => [], 'ufw' => 'ufw allow 80/tcp'],
            ['port' => 443, 'purpose' => 'https', 'projects' => [], 'ufw' => 'ufw allow 443/tcp'],
        ]], $this->json($json));
    }

    public function testPortInUseIsListedSortedWithItsProjects(): void
    {
        $this->cli('fleet:port:create', ['name' => 'typesense', '--public' => '9108', '--router' => '8108']);
        $this->cli('fleet:port:create', ['name' => 'unused', '--public' => '9200', '--router' => '8200']);
        $this->cli('fleet:port:create', ['name' => 'dash', '--public' => '4000', '--router' => '4001']);
        $this->cli('fleet:project:create', ['name' => 'blog', '--git' => 'https://git.example.com/blog.git', '--port' => ['typesense', 'dash']]);

        $json = $this->json($this->cli('fleet:port:firewall', ['--json' => true]));

        self::assertSame([80, 443, 4000, 9108], array_column($json['ports'], 'port'));
        self::assertSame(['blog'], $json['ports'][3]['projects']);
        self::assertSame('ufw allow 9108/tcp', $json['ports'][3]['ufw']);

        $text = $this->cli('fleet:port:firewall');
        self::assertSame(0, $text->getStatusCode());
        $display = $this->squash($text->getDisplay());
        self::assertMatchesRegularExpression('#\b9108 typesense blog ufw allow 9108/tcp#', $display);
        self::assertStringNotContainsString('9200', $display);
        self::assertStringContainsString('the installer does not change the firewall', strtolower($display));
    }
}
