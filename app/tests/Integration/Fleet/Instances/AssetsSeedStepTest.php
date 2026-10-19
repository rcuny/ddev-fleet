<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\AssetsSeedStep;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Jobs\JobFailed;
use App\Tests\Support\InstanceJobTestCase;

final class AssetsSeedStepTest extends InstanceJobTestCase
{
    private string $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->steps->append(new AssetsSeedStep($this->paths));
        $this->assets = $this->paths->assetsDir('acme');
        mkdir($this->assets.'/dumps', 0o750, true);
        mkdir($this->assets.'/web/sites/default', 0o750, true);
    }

    private function deploy(): \App\Domain\Job\Job
    {
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);

        return $job;
    }

    private function dir(): string
    {
        return $this->paths->instanceDir('acme--main');
    }

    public function testCopiesSubstitutesAndLeavesBigAndBinaryFilesAlone(): void
    {
        file_put_contents($this->assets.'/.env', "SITE=[[instance-fqdn]]\nKEEP=[[nope]]\n");
        file_put_contents($this->assets.'/web/sites/default/settings.local.php', "<?php // [[project]]/[[instance]]\n");
        $big = "[[project]]\n".str_repeat('a', 1_048_576);
        file_put_contents($this->assets.'/big.txt', $big);
        $binary = "\x00[[project]]\x01";
        file_put_contents($this->assets.'/blob.bin', $binary);
        file_put_contents($this->assets.'/latin1.txt', "caf\xe9 [[project]]");

        $job = $this->deploy();

        $dir = $this->dir();
        self::assertSame("SITE=acme--main.fleet.example.com\nKEEP=[[nope]]\n", file_get_contents($dir.'/.env'));
        self::assertSame("<?php // acme/main\n", file_get_contents($dir.'/web/sites/default/settings.local.php'));
        self::assertSame($big, file_get_contents($dir.'/big.txt'));
        self::assertSame($binary, file_get_contents($dir.'/blob.bin'));
        self::assertSame("caf\xe9 [[project]]", file_get_contents($dir.'/latin1.txt'));
        $log = $this->logText($job);
        self::assertStringContainsString('".env" has unresolved token(s) left as written: [[nope]]', $log);
        self::assertStringContainsString('assets: seeded 5 file(s), 2 with tokens substituted', $log);
        $exclude = (string) file_get_contents($dir.'/.git/info/exclude');
        self::assertStringContainsString("/.env\n", $exclude);
        self::assertStringContainsString("/web/sites/default/settings.local.php\n", $exclude);
        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
    }

    public function testDumpsAreHardLinkedUntouchedAndExcluded(): void
    {
        file_put_contents($this->assets.'/dumps/db.sql.gz', 'dump [[project]]');

        $job = $this->deploy();

        $copy = $this->dir().'/dumps/db.sql.gz';
        self::assertSame('dump [[project]]', file_get_contents($copy));
        self::assertSame(fileinode($this->assets.'/dumps/db.sql.gz'), fileinode($copy));
        self::assertStringContainsString("/dumps/\n", (string) file_get_contents($this->dir().'/.git/info/exclude'));
        self::assertStringContainsString('1 dump(s) linked', $this->logText($job));
    }

    public function testRedeployReplacesAStaleCopy(): void
    {
        file_put_contents($this->assets.'/dumps/db.sql.gz', 'new');
        file_put_contents($this->assets.'/.env', 'A=[[project]]');
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        mkdir($dir.'/dumps');
        file_put_contents($dir.'/dumps/db.sql.gz', 'stale');
        file_put_contents($dir.'/.env', 'stale');
        $job = $this->job('redeploy', $instance);

        // the same step run on an existing tree, as an idempotent second pass would
        $context = new DeployContext($instance, $this->project, $dir, ['project' => 'acme'], $this->logFor($job));
        new AssetsSeedStep($this->paths)->run($context);
        new AssetsSeedStep($this->paths)->run($context);

        self::assertSame('new', file_get_contents($dir.'/dumps/db.sql.gz'));
        self::assertSame(fileinode($this->assets.'/dumps/db.sql.gz'), fileinode($dir.'/dumps/db.sql.gz'));
        self::assertSame('A=acme', file_get_contents($dir.'/.env'));
    }

    public function testSymlinksAreSkippedAndGitIsNeverWritten(): void
    {
        file_put_contents($this->assets.'/real.txt', 'x');
        symlink('/etc/passwd', $this->assets.'/link.txt');
        mkdir($this->assets.'/.git');
        file_put_contents($this->assets.'/.git/config', 'evil');
        file_put_contents($this->assets.'/.fleet-upload-abc', 'half');

        $job = $this->deploy();

        $dir = $this->dir();
        self::assertFileExists($dir.'/real.txt');
        self::assertFileDoesNotExist($dir.'/link.txt');
        self::assertFileDoesNotExist($dir.'/.git/config');
        self::assertFileDoesNotExist($dir.'/.fleet-upload-abc');
        $log = $this->logText($job);
        self::assertStringContainsString('skipped "link.txt" (symbolic link)', $log);
        self::assertStringContainsString('skipped ".git"', $log);
    }

    public function testAMissingAssetsDirectoryIsANoOp(): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove($this->assets);

        $job = $this->deploy();

        self::assertStringContainsString('assets: none for this project, nothing to seed', $this->logText($job));
        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
    }

    public function testASymlinkedDestinationFolderFailsTheDeploy(): void
    {
        file_put_contents($this->assets.'/web/sites/default/x.txt', 'x');
        $outside = sys_get_temp_dir().'/seed-outside-'.bin2hex(random_bytes(3));
        mkdir($outside);
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        symlink($outside, $dir.'/web');
        $job = $this->job('deploy', $instance);

        try {
            new AssetsSeedStep($this->paths)->run(new DeployContext($instance, $this->project, $dir, [], $this->logFor($job)));
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('assets: "web" is a symbolic link in the checkout', $e->getMessage());
        } finally {
            self::assertSame([], array_diff(scandir($outside) ?: [], ['.', '..']));
            rmdir($outside);
        }
    }

    public function testAnUnreadableFileFailsTheDeployAndMarksTheInstanceFailed(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root reads everything');
        }
        file_put_contents($this->assets.'/secret.txt', 'x');
        chmod($this->assets.'/secret.txt', 0o000);
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);

        try {
            $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('assets: cannot read "secret.txt"', $e->getMessage());
        } finally {
            chmod($this->assets.'/secret.txt', 0o600);
        }
        self::assertSame(InstanceState::Failed, $instance->state());
        self::assertNotContains('ddev start', $this->commandNames());
    }

    public function testTheSeedRunsBeforeDdevStartAndBeforeAnAfterStartStep(): void
    {
        file_put_contents($this->assets.'/.env', 'A=1');
        $seen = new \ArrayObject();
        $after = new class($seen) implements DeployStep {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function phase(): StepPhase
            {
                return StepPhase::AfterStart;
            }

            public function run(DeployContext $context): void
            {
                $this->seen->append('tmux:env='.(is_file($context->dir.'/.env') ? 'yes' : 'no'));
            }
        };
        $this->steps->exchangeArray([$after, new AssetsSeedStep($this->paths)]);

        $this->deploy();

        self::assertSame(['tmux:env=yes'], $seen->getArrayCopy());
        self::assertSame(['git clone', 'caddy validate', 'caddy reload', 'ddev start', 'git rev-parse'], $this->commandNames());
    }
}
