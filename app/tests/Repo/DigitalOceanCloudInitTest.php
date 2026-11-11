<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-306: the cloud-init file for DigitalOcean, the installer's --domain=auto and the guide that ties them together.
 */
final class DigitalOceanCloudInitTest extends TestCase
{
    use InstallerHarness;

    private const FILE = '/deploy/cloud-init/digitalocean.yaml';

    public function testCloudInitFileShapeAndEmbeddedScriptSyntax(): void
    {
        $text = (string) file_get_contents(self::productRoot().self::FILE);

        self::assertStringStartsWith("#cloud-config\n", $text);
        self::assertStringContainsString("FLEET_DOMAIN='auto'", $text);
        self::assertStringContainsString('ufw allow 22/tcp', $text);
        self::assertStringContainsString('ufw allow 80/tcp', $text);
        self::assertStringContainsString('ufw allow 443/tcp', $text);
        self::assertStringContainsString('fleet setup:token', $text);
        self::assertStringContainsString('/root/FLEET-READY.txt', $text);
        // Let's Encrypt over HTTP-01 is the default; the OVH DNS-01 mode is never chosen here.
        self::assertStringNotContainsStringIgnoringCase('ovh', $text);
        // No secret, and example.com style addresses only.
        self::assertDoesNotMatchRegularExpression('/(token|password|secret)\s*=\s*[\'"]?[A-Za-z0-9]{12,}/i', $text);

        $script = $this->embeddedScript($text);
        $process = new Process(['bash', '-n'], null, null, $script, 10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testDomainAutoBecomesAnIpDerivedNipIoName(): void
    {
        [$status, $out] = $this->dryRun(['--domain=auto', '--skip-dns-check'], ['FLEET_PUBLIC_IPV4' => '203.0.113.7']);

        self::assertSame(0, $status);
        self::assertStringContainsString('203-0-113-7.nip.io', $out);
        self::assertStringContainsString('[preflight] domain 203-0-113-7.nip.io', $out);
    }

    public function testDomainAutoRefusesWithoutAnAddress(): void
    {
        [$status, , $err] = $this->bash([self::installDir().'/run.sh', '--domain=auto', '--skip-dns-check', '--preflight'], ['FLEET_PUBLIC_IPV4' => 'not-an-ip']);

        self::assertSame(64, $status);
        self::assertStringContainsString('--domain=auto', $err);
    }

    public function testGuideAndSamplesExist(): void
    {
        $root = self::productRoot();
        $guide = (string) file_get_contents($root.'/docs/guides/11-try-on-digitalocean.md');

        self::assertStringContainsString('nip.io', $guide);
        self::assertStringContainsString('doctl compute droplet create', $guide);
        self::assertStringContainsString('neither builds nor supports Fleet', $guide);
        self::assertStringContainsString('user_data = file(', (string) file_get_contents($root.'/deploy/terraform/digitalocean/main.tf'));
    }

    private function embeddedScript(string $yaml): string
    {
        $lines = [];
        $in = false;
        foreach (explode("\n", $yaml) as $line) {
            if (str_starts_with($line, 'runcmd:')) {
                break;
            }
            if ($in) {
                $lines[] = substr($line, 6);
            } elseif (str_contains($line, 'content: |')) {
                $in = true;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
