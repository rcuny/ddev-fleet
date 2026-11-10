<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\Check\CheckoutCleanCheck;
use App\Fleet\Upgrade\RepoUrl;
use Symfony\Component\Filesystem\Filesystem;

final class CheckoutCleanCheckTest extends CheckTestCase
{
    private const string HEAD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-checkout-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.git', 0o700, true);
        file_put_contents($this->dir.'/installed-revision', self::HEAD."\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private function runner(string $status = '', string $head = self::HEAD, string $origin = RepoUrl::DEFAULT): RecordingCommandRunner
    {
        return new RecordingCommandRunner()
            ->expect(['git', '-C', $this->dir, 'status'], new CommandResult(0, $status))
            ->expect(['git', '-C', $this->dir, 'rev-parse'], new CommandResult(0, $head."\n"))
            ->expect(['git', '-C', $this->dir, 'remote'], new CommandResult(0, $origin."\n"));
    }

    private function check(RecordingCommandRunner $runner, ?string $revisionFile = null): CheckoutCleanCheck
    {
        return new CheckoutCleanCheck($runner, new RepoUrl(), $this->dir, $revisionFile ?? $this->dir.'/installed-revision');
    }

    public function testACleanCheckoutAtTheInstalledRevisionIsQuiet(): void
    {
        self::assertSame([], $this->check($this->runner())->check(self::plan()));
    }

    public function testTheOriginMayOmitDotGit(): void
    {
        self::assertSame([], $this->check($this->runner(origin: 'https://github.com/rcuny/ddev-fleet'))->check(self::plan()));
    }

    public function testLocalChangesBlockAndAreNeverOverridable(): void
    {
        $results = $this->check($this->runner(" M app/src/Kernel.php\n"))->check(self::plan());

        self::assertSame([CheckoutCleanCheck::DIRTY], self::ids($results));
        self::assertFalse($results[0]->overridable);
        self::assertStringContainsString('Kernel.php', $results[0]->message);
    }

    public function testAMovedHeadBlocks(): void
    {
        $results = $this->check($this->runner(head: str_repeat('c', 40)))->check(self::plan());

        self::assertSame([CheckoutCleanCheck::MOVED], self::ids($results));
        self::assertFalse($results[0]->overridable);
    }

    public function testAHeadAtTheTargetCommitIsAResumedInstallAndPasses(): void
    {
        self::assertSame([], $this->check($this->runner(head: self::SHA))->check(self::plan()));
    }

    public function testAForeignOriginBlocks(): void
    {
        $results = $this->check($this->runner(origin: 'https://evil.example/ddev-fleet.git'))->check(self::plan());

        self::assertSame([CheckoutCleanCheck::ORIGIN], self::ids($results));
        self::assertFalse($results[0]->overridable);
    }

    public function testAnUnreadableRevisionFileWarnsInsteadOfGuessing(): void
    {
        $results = $this->check($this->runner(), $this->dir.'/missing')->check(self::plan());

        self::assertSame([CheckoutCleanCheck::REVISION_UNKNOWN], self::ids($results));
    }

    public function testNoCheckoutBlocks(): void
    {
        $check = new CheckoutCleanCheck(new RecordingCommandRunner(), new RepoUrl(), $this->dir.'/nope', $this->dir.'/installed-revision');

        $results = $check->check(self::plan());

        self::assertSame([CheckoutCleanCheck::MISSING], self::ids($results));
        self::assertFalse($results[0]->overridable);
    }

    public function testEveryCheckoutBlockIsNonOverridable(): void
    {
        $results = $this->check($this->runner(' M x', str_repeat('c', 40), 'https://x.example/y.git'))->check(self::plan());

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertFalse($result->overridable, $result->id);
        }
    }
}
