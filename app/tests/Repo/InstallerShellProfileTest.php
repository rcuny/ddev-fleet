<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Step 35 (the interactive shell profile) and its removal by the uninstaller: the dry-run plan, the opt-out flag, and the
 * hook helpers run for real against files in a scratch directory (never the host's /etc or ~/.bashrc).
 */
final class InstallerShellProfileTest extends TestCase
{
    use InstallerHarness;

    private const BEGIN = '# BEGIN ddev-fleet shell profile';
    private const END = '# END ddev-fleet shell profile';

    public function testDryRunPlansTheProfileAndTheHooksBetweenTheUserAndDdevSteps(): void
    {
        [$status, $out, $err] = $this->dryRun();

        self::assertSame(0, $status, $err);
        preg_match_all('/^==> (\S+)$/m', $out, $names);
        $steps = $names[1];
        self::assertSame(array_search('30-fleet-user', $steps, true) + 1, array_search('35-shell', $steps, true));
        self::assertSame(array_search('35-shell', $steps, true) + 1, array_search('40-ddev', $steps, true));

        $section = $this->section($out, '35-shell');
        $lines = [
            '+ write /etc/profile.d/10-fleet-shell.sh 0644 root:root',
            '+ hook /etc/bash.bashrc',
            '+ hook /root/.bashrc',
            '+ hook /home/fleet/.bashrc',
        ];
        $previous = -1;
        foreach ($lines as $line) {
            $at = strpos($section, $line);
            self::assertNotFalse($at, $line);
            self::assertGreaterThan($previous, $at, 'order: '.$line);
            $previous = $at;
        }
    }

    public function testOptOutSkipsTheStepAndChangesNothing(): void
    {
        [$status, $out, $err] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--no-shell-profile']);

        self::assertSame(0, $status, $err);
        $section = $this->section($out, '35-shell');
        self::assertStringContainsString('skipped: --no-shell-profile', $section);
        self::assertStringNotContainsString('+ write', $section);
        self::assertStringNotContainsString('+ hook', $section);
    }

    public function testTheFlagIsInBothUsageTextsAndIsAcceptedByBothStages(): void
    {
        foreach (['install.sh', 'lib/common.sh'] as $file) {
            self::assertStringContainsString('--no-shell-profile', (string) file_get_contents(self::installDir().'/'.$file), $file);
        }
        [$status, $out] = $this->dryRun(['--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('--no-shell-profile', $out);
    }

    public function testTheProfileFileIsInteractiveOnlyAndNeverExportsTheClaudeToken(): void
    {
        $file = self::installDir().'/etc/profile.d/10-fleet-shell.sh';
        $source = (string) file_get_contents($file);

        self::assertStringNotContainsString('CLAUDE_CODE_OAUTH_TOKEN', $source);
        self::assertStringNotContainsString('.secrets', $source);
        foreach (['CLICOLOR=1', 'EDITOR=nano', "alias l='ls -lah'", "alias ls='ls --color=auto'"] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('Copied from', (string) strtok($source, "\n"));

        // Non-interactive: nothing is set. Interactive: the prompt names the host in bold cyan and the user in bold red.
        [, $plain] = $this->bash(['-c', 'PS1=keep; . '.escapeshellarg($file).'; printf "%s|%s" "$PS1" "${EDITOR:-}"']);
        self::assertSame('keep|', $plain);
        [, $interactive] = $this->bash(['--norc', '-i', '-c', '. '.escapeshellarg($file).'; printf "%s" "$PS1"'], ['HOME' => $this->scratch(), 'TERM' => 'dumb']);
        self::assertSame('\[\e[1;36m\]\h\[\e[0m\] - \[\e[1;31m\]\u $\[\e[0m\]: \[\e[1;34m\]\w\[\e[0m\] > ', $interactive);
    }

    public function testHookIsAddedOnceAndRerunsAddNothing(): void
    {
        $rc = $this->scratch().'/bashrc';
        file_put_contents($rc, "# stock\nalias x=y");  // no trailing newline on purpose

        for ($i = 0; $i < 3; ++$i) {
            [$status, , $err] = $this->step('hook_file '.escapeshellarg($rc));
            self::assertSame(0, $status, $err);
        }

        $text = (string) file_get_contents($rc);
        self::assertSame(1, substr_count($text, self::BEGIN));
        self::assertSame(1, substr_count($text, self::END));
        self::assertSame("# stock\nalias x=y\n".self::BEGIN."\nif [ -f /etc/profile.d/10-fleet-shell.sh ]; then\n  . /etc/profile.d/10-fleet-shell.sh\nfi\n".self::END."\n", $text);
    }

    public function testAnExistingHookInAnyFormIsNotDuplicated(): void
    {
        $forms = [
            'ansible' => "# BEGIN ANSIBLE MANAGED BLOCK - fleet shell profile\nif [ -f /etc/profile.d/10-fleet-shell.sh ]; then\n    . /etc/profile.d/10-fleet-shell.sh\nfi\n# END ANSIBLE MANAGED BLOCK - fleet shell profile\n",
            'by hand' => "# fleet shell profile (prompt, aliases)\n[ -f /etc/profile.d/10-fleet-shell.sh ] && . /etc/profile.d/10-fleet-shell.sh\n",
        ];
        foreach ($forms as $name => $block) {
            $rc = $this->scratch().'/bashrc-'.md5($name);
            file_put_contents($rc, "# stock\n".$block);

            [$status, , $err] = $this->step('hook_file '.escapeshellarg($rc));

            self::assertSame(0, $status, $err);
            self::assertSame("# stock\n".$block, file_get_contents($rc), $name);
        }
    }

    public function testAMissingBashrcIsNotCreated(): void
    {
        $rc = $this->scratch().'/absent-bashrc';

        [$status, , $err] = $this->step('hook_file '.escapeshellarg($rc));

        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($rc);
    }

    public function testTargetsAreRootFleetAndTheInvokingSudoUserOnce(): void
    {
        [, $out] = $this->step('FLEET_HOME_DIR=/home/fleet; unset SUDO_USER; shell_hook_targets');
        self::assertSame("/root/.bashrc\n/home/fleet/.bashrc\n", $out);

        // root and fleet as the sudo user add nothing; a real account adds its home.
        foreach (['root', 'fleet'] as $user) {
            [, $out] = $this->step('FLEET_HOME_DIR=/home/fleet; SUDO_USER='.$user.'; shell_hook_targets');
            self::assertSame("/root/.bashrc\n/home/fleet/.bashrc\n", $out, $user);
        }
        $stubs = $this->stubs(['getent' => 'echo "alice:x:1000:1000::/home/alice:/bin/bash"']);
        [, $out] = $this->step('FLEET_HOME_DIR=/home/fleet; SUDO_USER=alice; shell_hook_targets', $stubs);
        self::assertSame("/root/.bashrc\n/home/fleet/.bashrc\n/home/alice/.bashrc\n", $out);
    }

    public function testStepWritesTheProfileHooksEverythingAndConvergesOnRerun(): void
    {
        $dir = $this->scratch();
        $profile = $dir.'/10-fleet-shell.sh';
        $system = $dir.'/bash.bashrc';
        $user = $dir.'/user-bashrc';
        file_put_contents($system, "# system\n");
        file_put_contents($user, "# user\n");
        $code = 'SHELL_PROFILE=1; SHELL_PROFILE_FILE='.escapeshellarg($profile).'; SHELL_PROFILE_BASHRC='.escapeshellarg($system).'; '
            .'shell_hook_targets() { printf "%s\n" '.escapeshellarg($user).' '.escapeshellarg($dir.'/missing').'; }; step_main; step_main';

        [$status, , $err] = $this->step($code, $this->stubs(['chown' => 'exit 0']));

        self::assertSame(0, $status, $err);
        self::assertSame('', $err);
        self::assertFileEquals(self::installDir().'/etc/profile.d/10-fleet-shell.sh', $profile);
        self::assertSame(0o644, fileperms($profile) & 0o777);
        foreach ([$system, $user] as $file) {
            $text = (string) file_get_contents($file);
            self::assertSame(1, substr_count($text, self::BEGIN), $file);
            self::assertStringContainsString('. '.$profile, $text);
        }
        self::assertFileDoesNotExist($dir.'/missing');
    }

    public function testStepWithTheFlagOffTouchesNothing(): void
    {
        $dir = $this->scratch();
        $profile = $dir.'/10-fleet-shell.sh';
        $system = $dir.'/bash.bashrc';
        file_put_contents($system, "# system\n");

        [$status, , $err] = $this->step('SHELL_PROFILE=0; SHELL_PROFILE_FILE='.escapeshellarg($profile).'; SHELL_PROFILE_BASHRC='.escapeshellarg($system).'; step_main', $this->stubs(['chown' => 'exit 0']));

        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($profile);
        self::assertSame("# system\n", file_get_contents($system));
    }

    public function testAHandCopiedFileIsReplacedSilentlyAndAForeignOneWithAWarning(): void
    {
        $profile = $this->scratch().'/10-fleet-shell.sh';
        $code = 'SHELL_PROFILE=1; SHELL_PROFILE_FILE='.escapeshellarg($profile).'; SHELL_PROFILE_BASHRC=/dev/null; shell_hook_targets() { :; }; step_main';
        $stubs = $this->stubs(['chown' => 'exit 0']);

        file_put_contents($profile, "# Copied from the old host by hand\nPS1=old\n");
        [$status, , $err] = $this->step($code, $stubs);
        self::assertSame(0, $status, $err);
        self::assertSame('', $err, 'a hand-copied file is replaced without a word');
        self::assertFileEquals(self::installDir().'/etc/profile.d/10-fleet-shell.sh', $profile);

        file_put_contents($profile, "PS1=somebody-elses\n");
        [$status, , $err] = $this->step($code, $stubs);
        self::assertSame(0, $status);
        self::assertStringContainsString('replacing '.$profile, $err);
        self::assertFileEquals(self::installDir().'/etc/profile.d/10-fleet-shell.sh', $profile);
    }

    public function testUninstallDryRunRemovesTheProfileAndTheHooks(): void
    {
        [$status, $out, $err] = $this->bash([self::installDir().'/uninstall.sh', '--yes', '--dry-run']);

        self::assertSame(0, $status, $err);
        foreach ([
            '+ rm -f -- /etc/profile.d/10-fleet-shell.sh',
            '+ strip_hook /etc/bash.bashrc ',
            '+ strip_hook /root/.bashrc ',
            '+ strip_hook /home/fleet/.bashrc ',
        ] as $line) {
            self::assertStringContainsString($line, $out);
        }
        self::assertLessThan((int) strpos($out, '+ userdel --remove fleet'), (int) strpos($out, '+ strip_hook /home/fleet/.bashrc'), 'the hook goes before the user and its home');
    }

    public function testUninstallRemovesOnlyAnExactBlockAndKeepsTheRest(): void
    {
        $dir = $this->scratch();
        $block = self::BEGIN."\nif [ -f /etc/profile.d/10-fleet-shell.sh ]; then\n  . /etc/profile.d/10-fleet-shell.sh\nfi\n".self::END."\n";

        $exact = $dir.'/exact';
        file_put_contents($exact, "# before\n".$block."# after\n");
        $edited = $dir.'/edited';
        $editedText = "# before\n".self::BEGIN."\nif [ -f /etc/profile.d/10-fleet-shell.sh ]; then\n  . /etc/profile.d/10-fleet-shell.sh\n  echo mine\nfi\n".self::END."\n# after\n";
        file_put_contents($edited, $editedText);
        $unterminated = $dir.'/unterminated';
        file_put_contents($unterminated, "# before\n".self::BEGIN."\nsomething\n");
        $twice = $dir.'/twice';
        file_put_contents($twice, $block.'# mid'."\n".$block);

        $strip = static fn (string $file): string => 'strip_hook '.escapeshellarg($file).' '.escapeshellarg(self::BEGIN).' '.escapeshellarg(self::END).' /etc/profile.d/10-fleet-shell.sh; ';
        [$status, , $err] = $this->bash(['-c', 'set -Eeuo pipefail; do_cmd() { "$@"; }; '
            .'eval "$(sed -n "/^  strip_hook() {/,/^  }/p" '.escapeshellarg(self::installDir().'/uninstall.sh').')"; '
            .$strip($exact).$strip($edited).$strip($unterminated).$strip($twice).$strip($dir.'/absent')]);

        self::assertSame(0, $status, $err);
        self::assertSame("# before\n# after\n", file_get_contents($exact));
        self::assertSame($editedText, file_get_contents($edited), 'an edited block is not ours to remove');
        self::assertSame("# before\n".self::BEGIN."\nsomething\n", file_get_contents($unterminated));
        self::assertSame("# mid\n", file_get_contents($twice));
        self::assertFileDoesNotExist($dir.'/absent');
    }

    public function testTheUninstallerBlockMatchesTheStepsBlock(): void
    {
        [, $block] = $this->step('shell_hook_block');
        $uninstall = (string) file_get_contents(self::installDir().'/uninstall.sh');

        self::assertStringContainsString("hook_begin='".self::BEGIN."'", $uninstall);
        self::assertStringContainsString("hook_end='".self::END."'", $uninstall);
        self::assertStringContainsString("shell_profile=/etc/profile.d/10-fleet-shell.sh\n", $uninstall);
        self::assertStringContainsString('buf[3] == "  . " p', $uninstall);
        self::assertSame(self::BEGIN."\nif [ -f /etc/profile.d/10-fleet-shell.sh ]; then\n  . /etc/profile.d/10-fleet-shell.sh\nfi\n".self::END."\n", $block);
    }

    /**
     * Runs shell code with common.sh and step 35 sourced.
     *
     * @return array{int, string, string}
     */
    private function step(string $code, ?string $stubDir = null): array
    {
        return $this->withCommon('INSTALL_DIR='.escapeshellarg(self::installDir()).'; . "$INSTALL_DIR/steps/35-shell.sh"; '.$code, [], $stubDir);
    }

    private function section(string $plan, string $step): string
    {
        $start = strpos($plan, '==> '.$step."\n");
        self::assertNotFalse($start, $step);
        $next = strpos($plan, "\n==> ", $start + 1);

        return substr($plan, $start, false === $next ? null : $next - $start);
    }
}
