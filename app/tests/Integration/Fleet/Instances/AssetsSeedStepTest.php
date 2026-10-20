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
    private string $secretPath = '';

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

    public function testSecretTokensInAssetsAreSubstitutedAndMasked(): void
    {
        $this->secretStore->putManaged('acme', 'DB_PASS', 'asset-s3cret-value', 'test');
        file_put_contents($this->assets.'/.env', "PASS=[[db-pass]]\nOTHER=[[unknown-secret]]\n");

        $job = $this->deploy();

        self::assertSame("PASS=asset-s3cret-value\nOTHER=[[unknown-secret]]\n", file_get_contents($this->dir().'/.env'));
        $log = $this->logText($job);
        self::assertStringContainsString('unresolved token(s) left as written: [[unknown-secret]]', $log);
        self::assertStringNotContainsString('asset-s3cret-value', $log);
        self::assertSame('[masked]', $this->masker->mask('asset-s3cret-value'));
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
        $context = new DeployContext($instance, $this->project, $dir, ['project' => 'acme'], $this->logFor($job), $this->secretRenderer);
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
        self::assertStringContainsString("skipped \"link.txt\" (symbolic link to \"/etc/passwd\": points outside the project's assets)", $log);
        self::assertStringContainsString('skipped ".git"', $log);
    }

    public function testALinkInDumpsToAnotherDumpBecomesAHardLink(): void
    {
        file_put_contents($this->assets.'/dumps/dump-prod-2026-10-15.sql', 'seed [[project]]');
        symlink('dump-prod-2026-10-15.sql', $this->assets.'/dumps/default.sql');

        $job = $this->deploy();

        $copy = $this->dir().'/dumps/default.sql';
        self::assertFalse(is_link($copy));
        self::assertSame('seed [[project]]', file_get_contents($copy));
        self::assertSame(fileinode($this->assets.'/dumps/dump-prod-2026-10-15.sql'), fileinode($copy));
        self::assertStringContainsString('0 entry(ies) skipped', $this->logText($job));
    }

    public function testARelativeLinkOutsideDumpsStaysARelativeLinkToTheSeededTarget(): void
    {
        file_put_contents($this->assets.'/web/sites/default/settings.real.php', '<?php // [[project]]');
        file_put_contents($this->assets.'/dumps/db.sql', 'x');
        symlink('../../../web/sites/default/settings.real.php', $this->assets.'/web/sites/default/settings.local.php');
        symlink('settings.real.php', $this->assets.'/web/sites/default/other.php');
        symlink('../../../dumps/db.sql', $this->assets.'/web/sites/default/db.sql');

        $this->deploy();

        $default = $this->dir().'/web/sites/default';
        self::assertSame('settings.real.php', readlink($default.'/settings.local.php'));
        self::assertSame('settings.real.php', readlink($default.'/other.php'));
        self::assertSame('<?php // acme', file_get_contents($default.'/settings.local.php'));
        self::assertSame('../../../dumps/db.sql', readlink($default.'/db.sql'));
        self::assertSame('x', file_get_contents($default.'/db.sql'));
        $exclude = (string) file_get_contents($this->dir().'/.git/info/exclude');
        self::assertStringContainsString("/web/sites/default/other.php\n", $exclude);
    }

    public function testALinkChainInsideTheAssetsIsHonoured(): void
    {
        file_put_contents($this->assets.'/dumps/real.sql', 'r');
        symlink('real.sql', $this->assets.'/dumps/mid.sql');
        symlink('mid.sql', $this->assets.'/dumps/default.sql');

        $this->deploy();

        self::assertSame('r', file_get_contents($this->dir().'/dumps/default.sql'));
        self::assertSame(fileinode($this->assets.'/dumps/real.sql'), fileinode($this->dir().'/dumps/default.sql'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'escape by ..' => ['../../outside.sql', 'points outside the project\'s assets'];
        yield 'absolute outside' => ['/etc/hostname', 'points outside the project\'s assets'];
        yield 'dangling' => ['nowhere.sql', 'dangling'];
        yield 'folder' => ['../web', 'points at a folder'];
        yield 'loop' => ['default.sql', 'a loop or a chain'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedLinks')]
    public function testARefusedLinkIsSkippedWithItsReason(string $target, string $reason): void
    {
        file_put_contents($this->assets.'/dumps/ok.sql', 'ok');
        symlink($target, $this->assets.'/dumps/default.sql');

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->dir().'/dumps/default.sql');
        self::assertFileExists($this->dir().'/dumps/ok.sql');
        $log = $this->logText($job);
        self::assertStringContainsString('skipped "dumps/default.sql" (symbolic link to "'.$target.'": '.$reason, $log);
    }

    public function testAChainThatLeavesTheTreeAndComesBackIsRefused(): void
    {
        $outside = \dirname($this->assets).'/outside-hop.sql';
        symlink($this->assets.'/dumps/real.sql', $outside);
        file_put_contents($this->assets.'/dumps/real.sql', 'r');
        symlink($outside, $this->assets.'/dumps/default.sql');

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->dir().'/dumps/default.sql');
        self::assertStringContainsString('points outside the project\'s assets', $this->logText($job));
        @unlink($outside);
    }

    public function testALinkBehindAFolderLinkIsRefused(): void
    {
        mkdir($this->assets.'/real', 0o750);
        file_put_contents($this->assets.'/real/x.sql', 'x');
        symlink('real', $this->assets.'/alias');
        symlink('../alias/x.sql', $this->assets.'/dumps/default.sql');

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->dir().'/dumps/default.sql');
        self::assertStringContainsString('goes through a folder that is a symbolic link', $this->logText($job));
    }

    public function testALinkIntoAnotherProjectsAssetsIsRefused(): void
    {
        $other = $this->paths->assetsDir('other');
        mkdir($other.'/dumps', 0o750, true);
        file_put_contents($other.'/dumps/x.sql', 'secret');
        file_put_contents($this->assets.'/dumps/ok.sql', 'ok');
        symlink('../../other/dumps/x.sql', $this->assets.'/dumps/default.sql');
        symlink($other.'/dumps/x.sql', $this->assets.'/dumps/abs.sql');
        // `../<this project>/x` climbs out of the assets directory and back in: refused as well.
        symlink('../acme/dumps/ok.sql', $this->assets.'/back.sql');

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->dir().'/dumps/default.sql');
        self::assertFileDoesNotExist($this->dir().'/dumps/abs.sql');
        self::assertFileDoesNotExist($this->dir().'/back.sql');
        self::assertFalse(is_link($this->dir().'/back.sql'));
        self::assertSame(3, substr_count($this->logText($job), 'points outside the project\'s assets'));
    }

    public function testALinkToASpecialFileIsRefused(): void
    {
        if (!\function_exists('posix_mkfifo') || !posix_mkfifo($this->assets.'/pipe', 0o600)) {
            self::markTestSkipped('cannot create a fifo here');
        }
        symlink('../pipe', $this->assets.'/dumps/default.sql');
        symlink('/dev/null', $this->assets.'/dev.sql');

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->dir().'/dumps/default.sql');
        $log = $this->logText($job);
        self::assertStringContainsString('skipped "dumps/default.sql" (symbolic link to "../pipe": points at a special file', $log);
        self::assertStringContainsString('skipped "dev.sql" (symbolic link to "/dev/null": points outside', $log);
        self::assertStringContainsString('skipped "pipe" (not a regular file)', $log);
    }

    public function testAChainOfExactlyTheCapIsHonouredAndOneMoreIsRefused(): void
    {
        file_put_contents($this->assets.'/dumps/real.sql', 'r');
        // four links in a row end at the file: allowed
        symlink('real.sql', $this->assets.'/dumps/l4.sql');
        symlink('l4.sql', $this->assets.'/dumps/l3.sql');
        symlink('l3.sql', $this->assets.'/dumps/l2.sql');
        symlink('l2.sql', $this->assets.'/dumps/l1.sql');
        // a fifth hop is over the cap
        symlink('l1.sql', $this->assets.'/dumps/l0.sql');

        $job = $this->deploy();

        self::assertSame('r', file_get_contents($this->dir().'/dumps/l1.sql'));
        self::assertFileDoesNotExist($this->dir().'/dumps/l0.sql');
        self::assertStringContainsString('skipped "dumps/l0.sql" (symbolic link to "l1.sql": a loop or a chain of more than 4 links', $this->logText($job));
    }

    public function testASourceSwappedForALinkAfterTheCheckFailsTheDeployWithoutCopyingIt(): void
    {
        $secret = sys_get_temp_dir().'/seed-secret-'.bin2hex(random_bytes(3));
        file_put_contents($secret, 'TOP SECRET');
        file_put_contents($this->assets.'/dumps/real.sql', 'r');
        symlink('real.sql', $this->assets.'/dumps/default.sql');
        file_put_contents($this->assets.'/blob.bin', "\x00data");

        $swap = function (string $src): void {
            if (str_ends_with($src, '/dumps/real.sql') || str_ends_with($src, '/blob.bin')) {
                unlink($src);
                symlink($this->secretPath, $src);
            }
        };
        $this->secretPath = $secret;
        $this->steps->exchangeArray([new AssetsSeedStep($this->paths, $swap)]);
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);

        try {
            $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('changed while it was being seeded', $e->getMessage());
        } finally {
            @unlink($secret);
        }
        foreach (['dumps/real.sql', 'dumps/default.sql', 'blob.bin'] as $name) {
            $copy = $this->dir().'/'.$name;
            self::assertTrue(!file_exists($copy) || 'TOP SECRET' !== file_get_contents($copy), $name.' must not hold the secret');
        }
    }

    public function testAFileSwappedForAnotherInodeBetweenTheOpenAndTheRecheckFailsTheDeploy(): void
    {
        file_put_contents($this->assets.'/blob.bin', "\x00data");

        $swap = static function (string $src): void {
            if (str_ends_with($src, '/blob.bin')) {
                rename($src, $src.'.old');
                file_put_contents($src, "\x00other");
            }
        };
        $this->steps->exchangeArray([new AssetsSeedStep($this->paths, null, $swap)]);

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('changed while it was being seeded');
        $instance = $this->instance();
        $this->workflow->run($instance, $this->project, false, $this->logFor($this->job('deploy', $instance)), false);
    }

    public function testAFolderLinkSwappedInAfterTheLeafWasOpenedIsRefusedByTheDescriptorPath(): void
    {
        $outside = sys_get_temp_dir().'/seed-outside-'.bin2hex(random_bytes(3));
        mkdir($outside);
        file_put_contents($outside.'/settings.local.php', 'TOP SECRET');
        file_put_contents($this->assets.'/web/sites/default/settings.local.php', 'inside');
        $folder = $this->assets.'/web/sites/default';

        // Swap the folder after the open: the leaf path now resolves outside, so only the descriptor tells the truth.
        $swap = static function (string $src) use ($outside, $folder): void {
            if (str_ends_with($src, '/settings.local.php') && !is_link($folder)) {
                rename($folder, $folder.'.old');
                symlink($outside, $folder);
            }
        };
        $this->steps->exchangeArray([new AssetsSeedStep($this->paths, null, $swap)]);
        $instance = $this->instance();

        try {
            $this->workflow->run($instance, $this->project, false, $this->logFor($this->job('deploy', $instance)), false);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('settings.local.php', $e->getMessage());
        } finally {
            @unlink($outside.'/settings.local.php');
            @rmdir($outside);
        }
        $copy = $this->dir().'/web/sites/default/settings.local.php';
        self::assertTrue(!file_exists($copy) || 'TOP SECRET' !== file_get_contents($copy));
    }

    public function testAFifoSwappedInAfterTheCheckDoesNotHangTheDeploy(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is not available');
        }
        file_put_contents($this->assets.'/blob.bin', "\x00data");

        $swap = static function (string $src): void {
            if (str_ends_with($src, '/blob.bin')) {
                unlink($src);
                posix_mkfifo($src, 0o600);
            }
        };
        $this->steps->exchangeArray([new AssetsSeedStep($this->paths, $swap)]);
        $instance = $this->instance();

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('changed while it was being seeded');
        $this->workflow->run($instance, $this->project, false, $this->logFor($this->job('deploy', $instance)), false);
    }

    public function testAHardLinkInsideTheAssetsIsTheSameInodeAndIsCopied(): void
    {
        $outside = sys_get_temp_dir().'/seed-hard-'.bin2hex(random_bytes(3));
        file_put_contents($outside, 'OUTSIDE CONTENT');
        if (!@link($outside, $this->assets.'/linked.bin')) {
            @unlink($outside);
            self::markTestSkipped('hard links across these directories are not possible here');
        }

        try {
            $this->deploy();
        } finally {
            @unlink($outside);
        }

        // Documented behaviour: a hard link is a regular file with its own name; whoever made it already could read the target.
        self::assertSame('OUTSIDE CONTENT', file_get_contents($this->dir().'/linked.bin'));
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
            new AssetsSeedStep($this->paths)->run(new DeployContext($instance, $this->project, $dir, [], $this->logFor($job), $this->secretRenderer));
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

    public function testRenderedFilesGetTheirFinalModeEvenUnderAnOpenUmask(): void
    {
        file_put_contents($this->assets.'/.env', 'K=[[project]]');
        chmod($this->assets.'/.env', 0o600);
        file_put_contents($this->assets.'/blob.bin', "\x00x");
        chmod($this->assets.'/blob.bin', 0o640);
        $old = umask(0o000);
        try {
            $this->deploy();
            self::assertSame(0o000, umask(), 'the step restores the umask');
        } finally {
            umask($old);
        }

        clearstatcache();
        self::assertSame(0o600, fileperms($this->dir().'/.env') & 0o777);
        self::assertSame(0o640, fileperms($this->dir().'/blob.bin') & 0o777);
        self::assertSame([], glob($this->dir().'/*.fleet-seed-*') ?: []);
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
