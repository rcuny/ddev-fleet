<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The persistent `fleet` tmux session of the agent workspace: a `general` window (a shell in FLEET_HOME) and one window per
 * instance, named after the instance id. Every window is `[sidebar 50 cols | tty1 | tty2]`, the two shells side by side and
 * equal; the sidebar is tagged `@fleet_role=sidebar`, every managed window `@fleet_managed=1`. Runs as whoever runs the app
 * (the fleet user); the tmux server lives on its own socket (`tmux -L fleet`) and in the cgroup of fleet-tmux.service, so
 * restarting php-fpm or the workers leaves it alone.
 *
 * Every call is an argv array through the CommandRunner. The panes are plain shells: this service never types a tty command
 * by itself, a caller does with sendKeys() (the deploy step).
 *
 * The sidebar pane runs through the application environment (the env file the CLI wrapper loads): a pane command inherits
 * the tmux server's environment, which has no APP_ENV or FLEET_HOME. If it still exits, the pane stays and shows why, so a
 * crash never changes the pane count (and never renumbers tty1 and tty2).
 */
final readonly class Tmux
{
    public const string SESSION = 'fleet';
    public const string SOCKET = 'fleet';
    /** The window the session starts with: a shell in FLEET_HOME. Instance ids contain `--`, so it cannot collide. */
    public const string HOME_WINDOW = 'general';
    /** What the home window was called before 1.1.x; a window with that name is renamed. */
    public const string LEGACY_HOME_WINDOW = 'host';
    public const int SIDEBAR_WIDTH = 50;
    /** What sendToAgent() reports: typed only (no Enter asked), submitted, submitted after a second Enter, or still in the input box. */
    public const string SEND_TYPED = 'typed';
    public const string SEND_SUBMITTED = 'submitted';
    public const string SEND_RETRIED = 'retried';
    public const string SEND_UNCONFIRMED = 'unconfirmed';
    /** The size a detached session starts with (tmux would pick 80x24); an attaching client resizes it. Also the session's `default-size`, so windows
     *  created while detached are not 80x24 either (FLE-187). */
    private const string DETACHED_WIDTH = '300';
    private const string DETACHED_HEIGHT = '80';
    /** A shell pane narrower than this is not worth equalising (the 0.x floor). */
    private const int MIN_SHELL_WIDTH = 10;
    public const int COMMAND_MAX = 4096;
    public const float TIMEOUT = 15.0;

    /** The only foreground commands API terminal input may reach (FLE-286): the agent, never a shell or any other program. */
    public const array AGENTS = ['claude'];

    /** The timeout of the `docker exec ... ps` that looks inside an instance container (FLE-352). */
    private const float DOCKER_TIMEOUT = 8.0;

    /** Foreground commands that mean "a shell at its prompt"; under an agent, a shell leaf must run a command (`-c`, FLE-361). */
    private const array SHELLS = ['bash', 'sh', 'zsh', 'dash', 'fish', 'ash', 'ksh', '-bash', '-sh', '-zsh'];

    private const string PANE_FORMAT = "#{pane_id}\t#{pane_left}\t#{pane_top}\t#{pane_width}\t#{window_width}\t#{@fleet_role}\t#{pane_start_command}";

    /** Runs the command with the env file loaded (`set -a`), the way the CLI wrapper does; the secrets stay in the file. */
    private const string ENV_SHELL = 'set -a; [ -r "$0" ] && . "$0"; set +a; exec "$@"';
    /** The same, but the pane survives the command: its error stays on screen and the pane count never changes. */
    private const string KEEP_SHELL = 'set -a; [ -r "$0" ] && . "$0"; set +a; "$@"; rc=$?; printf "\nfleet: the sidebar exited (status %s).\n" "$rc"; while :; do sleep 3600; done';

    public function __construct(
        private CommandRunner $runner,
        private FleetPaths $paths,
        #[Autowire('%kernel.project_dir%/bin/console')]
        private string $console,
        private string $php = '/usr/bin/php8.4',
        private string $envFile = '/etc/ddev-fleet/fleet.env',
        /** Pause between the typed text and Enter, and before the submit check (FLE-359). */
        private int $submitDelayMicros = 300_000,
    ) {
    }

    public function hasSession(): bool
    {
        return $this->tmux(['has-session', '-t', '='.self::SESSION])->isSuccess();
    }

    /**
     * Creates the session (with its `general` window and the settings) when it does not exist. Returns whether it was created.
     */
    public function ensureSession(): bool
    {
        if ($this->hasSession()) {
            return false;
        }
        $home = $this->paths->home();
        $this->must(['new-session', '-d', '-s', self::SESSION, '-n', self::HOME_WINDOW, '-c', $home, '-x', self::DETACHED_WIDTH, '-y', self::DETACHED_HEIGHT], 'create the session');
        $this->applySettings();
        $this->heal(self::HOME_WINDOW, $home);

        return true;
    }

    /**
     * Makes sure the `general` window exists and is whole: a window still called `host` is renamed. Returns whether it was created.
     *
     * @throws WorkspaceException
     */
    public function ensureGeneral(): bool
    {
        $this->ensureSession();
        $windows = $this->listWindows();
        $home = $this->paths->home();
        if (\in_array(self::HOME_WINDOW, $windows, true)) {
            $this->heal(self::HOME_WINDOW, $home);

            return false;
        }
        if (\in_array(self::LEGACY_HOME_WINDOW, $windows, true)) {
            $this->must(['rename-window', '-t', self::window(self::LEGACY_HOME_WINDOW), self::HOME_WINDOW], 'rename the host window');
            $this->heal(self::HOME_WINDOW, $home);

            return false;
        }
        $this->createWindow(self::HOME_WINDOW, $home);

        return true;
    }

    /**
     * Session options, the status bar and `^b R` (rebuild the current tab). Idempotent: run at session creation and on every attach.
     *
     * @throws WorkspaceException
     */
    public function applySettings(): void
    {
        $session = '='.self::SESSION.':';
        foreach ([
            ['set-option', '-t', $session, 'mouse', 'on'],
            ['set-option', '-t', $session, 'default-size', self::DETACHED_WIDTH.'x'.self::DETACHED_HEIGHT],
            ['set-option', '-t', $session, 'window-size', 'latest'],
            ['set-option', '-s', 'set-clipboard', 'on'],
            ['set-option', '-t', $session, 'status', 'on'],
            ['set-option', '-t', $session, 'status-position', 'bottom'],
            ['set-option', '-t', $session, 'window-status-format', ' #I:#W '],
            ['set-option', '-t', $session, 'window-status-current-format', ' #I:#W '],
            ['set-option', '-t', $session, 'window-status-current-style', 'reverse,bold'],
        ] as $args) {
            $this->must($args, 'set a session option');
        }
        // The pane id is `%` and digits, so it is safe inside the shell string; the window name is read back from tmux by
        // the command (fleet:tmux:reset --pane) and never put on a command line.
        $reset = $this->envShell([$this->php, $this->console, 'fleet:tmux:reset', '--pane', '#{pane_id}']);
        $this->must(['bind-key', 'R', 'run-shell', '-b', $reset], 'bind ^b R');
    }

    /**
     * The window names, or an empty list when there is no session.
     *
     * @return list<string>
     */
    public function listWindows(): array
    {
        $result = $this->tmux(['list-windows', '-t', '='.self::SESSION, '-F', '#{window_name}']);
        if (!$result->isSuccess()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->stdout)), static fn (string $name): bool => '' !== $name));
    }

    /**
     * The windows this service manages (tagged `@fleet_managed=1`): the only ones a reconcile may prune.
     *
     * @return list<string>
     */
    public function managedWindows(): array
    {
        $result = $this->tmux(['list-windows', '-t', '='.self::SESSION, '-F', "#{window_name}\t#{@fleet_managed}"]);
        if (!$result->isSuccess()) {
            return [];
        }
        $names = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $fields = explode("\t", rtrim($line, "\r"));
            if ('' !== $fields[0] && '1' === ($fields[1] ?? '')) {
                $names[] = $fields[0];
            }
        }

        return $names;
    }

    /**
     * Creates the window of an instance (sidebar, tty1, tty2, all in its directory) unless it exists; an existing one is
     * healed in place (a missing sidebar or second shell is added, nothing is killed). Returns whether it was created.
     *
     * @throws WorkspaceException
     */
    public function ensureWindow(string $instanceId, string $dir): bool
    {
        self::assertId($instanceId);
        if ($dir !== $this->paths->instanceDir($instanceId)) {
            throw new WorkspaceException('The directory is not the instance directory.');
        }
        $this->ensureSession();
        if (\in_array($instanceId, $this->listWindows(), true)) {
            $this->heal($instanceId, $dir);

            return false;
        }
        $this->createWindow($instanceId, $dir);

        return true;
    }

    /**
     * Closes the window of an instance. Returns whether there was one.
     *
     * @throws WorkspaceException
     */
    public function closeWindow(string $instanceId): bool
    {
        self::assertId($instanceId);
        if (!\in_array($instanceId, $this->listWindows(), true)) {
            return false;
        }
        $this->must(['kill-window', '-t', self::window($instanceId)], 'close the window');

        return true;
    }

    /**
     * Types $command literally into tty1 or tty2 of the window, then Enter. The caller builds the command line; a control
     * character (a newline would run a second command, an escape drives the terminal) is refused. The pane is found by
     * position (the shells left to right), not by index, so a window whose sidebar is gone still routes right.
     *
     * @throws WorkspaceException
     */
    public function sendKeys(string $instanceId, Pane $pane, string $command): void
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane) {
            throw new WorkspaceException('Keys are sent to tty1 or tty2, not to the sidebar.');
        }
        if ('' === $command || \strlen($command) > self::COMMAND_MAX || 1 === preg_match('/[\x00-\x1f\x7f]/', $command)) {
            throw new WorkspaceException('The command is empty, too long, or contains a control character.');
        }
        if (!\in_array($instanceId, $this->listWindows(), true)) {
            throw new WorkspaceException('The instance has no window.');
        }
        $target = self::shellFor($this->panes($instanceId), $pane);
        if (null === $target) {
            throw new WorkspaceException(\sprintf('The window has no %s pane.', strtolower($pane->name)));
        }

        $this->must(['send-keys', '-t', $target->id, '-l', '--', $command], 'send the keys');
        $this->must(['send-keys', '-t', $target->id, 'Enter'], 'send Enter');
    }

    /**
     * Whether tty1 or tty2 of the window is running something: the foreground command is not a shell, or the shell has
     * child processes (a job in the background). A pane that cannot be found counts as idle.
     *
     * @throws WorkspaceException
     */
    public function isBusy(string $instanceId, Pane $pane): bool
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane) {
            throw new WorkspaceException('Only tty1 or tty2 can be busy, not the sidebar.');
        }
        $target = self::shellFor($this->panes($instanceId), $pane);
        if (null === $target) {
            return false;
        }
        $result = $this->tmux(['display-message', '-p', '-t', $target->id, "#{pane_current_command}\t#{pane_pid}"]);
        if (!$result->isSuccess()) {
            return false;
        }
        [$command, $pid] = array_pad(explode("\t", trim($result->stdout), 2), 2, '');
        if ('' !== $command && !\in_array($command, self::SHELLS, true)) {
            return true;
        }
        if (1 !== preg_match('/^[0-9]+$/D', $pid)) {
            return false;
        }

        return $this->runner->run(['pgrep', '-P', $pid], null, [], null, self::TIMEOUT)->isSuccess();
    }

    /**
     * The pane API terminal input would type into, found strictly and checked: tty1 or tty2 by its `tty1`/`tty2` tag (or, in an
     * untagged window with exactly two shells, by position; a window with any other number of shells has no addressable pane),
     * never the sidebar and never `general`. Null when the pane does not exist; the foreground command is read now.
     *
     * @throws WorkspaceException
     */
    public function agentPane(string $instanceId, Pane $pane): ?AgentPane
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane) {
            throw new WorkspaceException('Keys are sent to tty1 or tty2, not to the sidebar.');
        }
        if (!\in_array($instanceId, $this->listWindows(), true)) {
            return null;
        }
        $target = self::strictShell($this->panes($instanceId), $pane);
        if (null === $target) {
            return null;
        }
        $state = $this->paneState($target->id);
        if (null === $state || $state['window'] !== $instanceId) {
            return null;
        }

        $effective = $this->effectiveForeground($instanceId, $state);

        return new AgentPane($target->id, $effective['display'] ?? $state['command'], $effective['command'] ?? null);
    }

    /**
     * Types $text (no control characters; the caller validated it) into an agent pane, then Enter when asked. Re-reads the pane
     * immediately before sending and refuses unless it is still the same pane of the same window, alive, with an agent in the
     * foreground. tmux cannot check and send atomically: what is left is the time between that read and the keystroke, a few
     * milliseconds (the residual risk is documented in docs/reference/15-api.md).
     *
     * Enter is a separate keystroke after a short pause (FLE-359: text and Enter in one burst can reach Claude Code as a paste,
     * where Enter is a newline). The pane is then read back: if the text is still in the input box, Enter is sent once more.
     *
     * @return self::SEND_*
     *
     * @throws WorkspaceException
     */
    public function sendToAgent(string $instanceId, AgentPane $pane, string $text, bool $enter): string
    {
        self::assertId($instanceId);
        if ('' === $text || \strlen($text) > self::COMMAND_MAX || 1 === preg_match('/[\x00-\x1f\x7f]/', $text)) {
            throw new WorkspaceException('The text is empty, too long, or contains a control character.');
        }
        $state = $this->paneState($pane->id);
        if (null === $state || $state['window'] !== $instanceId || $state['dead'] || null === $this->effectiveForeground($instanceId, $state)) {
            throw new WorkspaceException('The pane is no longer running an agent.');
        }

        $this->must(['send-keys', '-t', $pane->id, '-l', '--', $text], 'send the keys');
        if ($enter) {
            $this->pause();
            // Again between the text and Enter: if the agent is gone, the text is left unsubmitted rather than run by a shell.
            $this->assertStillAgent($instanceId, $pane, 'before Enter');
            $this->must(['send-keys', '-t', $pane->id, 'Enter'], 'send Enter');
            $this->pause();
            if (!$this->stillInInput($pane->id, $text)) {
                return self::SEND_SUBMITTED;
            }
            // The retry Enter follows a pause too: the agent may have exited meanwhile, and a shell would run the typed text.
            $this->assertStillAgent($instanceId, $pane, 'before the second Enter');
            $this->must(['send-keys', '-t', $pane->id, 'Enter'], 'send Enter again');
            $this->pause();

            return $this->stillInInput($pane->id, $text) ? self::SEND_UNCONFIRMED : self::SEND_RETRIED;
        }

        return self::SEND_TYPED;
    }

    /** @throws WorkspaceException when the pane is no longer the instance's live agent pane (no Enter must follow) */
    private function assertStillAgent(string $instanceId, AgentPane $pane, string $when): void
    {
        $state = $this->paneState($pane->id);
        if (null === $state || $state['window'] !== $instanceId || $state['dead'] || null === $this->effectiveForeground($instanceId, $state)) {
            throw new WorkspaceException(\sprintf('The pane stopped running an agent %s; Enter was not sent.', $when));
        }
        // A usage-limit dialog may have appeared during the pause: never submit text into it (FLE-368).
        if (null !== $this->usageLimitOf($pane->id)) {
            throw new WorkspaceException(\sprintf('The agent showed its usage-limit dialog %s; Enter was not sent.', $when));
        }
    }

    private function pause(): void
    {
        if ($this->submitDelayMicros > 0) {
            usleep($this->submitDelayMicros);
        }
    }

    /** True when the last 40 characters of $text are still in the agent's input box (the capture could not be read: false). */
    private function stillInInput(string $paneId, string $text): bool
    {
        $input = $this->readInput($paneId);
        if (null === $input || $input['ghost']) {
            return false;
        }
        $tail = mb_substr($text, -40);

        return '' !== $tail && str_contains($input['text'], $tail);
    }

    /**
     * What the agent's input box holds, from `capture-pane -e`: the last line that starts with the `❯` prompt. `ghost` is true when
     * the text after it is dim or grey, which is how Claude Code draws its prompt suggestion (greyed, not typed; the capture without
     * escapes cannot tell them apart, FLE-359). A heuristic on SGR codes: dim (2), bright black (90), or a grey 256/true colour.
     *
     * @return array{text: string, ghost: bool}|null null when there is no prompt line
     */
    private function readInput(string $paneId): ?array
    {
        $result = $this->tmux(['capture-pane', '-p', '-e', '-t', $paneId, '-S', '-12']);
        if (!$result->isSuccess()) {
            return null;
        }
        $found = null;
        foreach (explode("\n", $result->stdout) as $line) {
            if (1 === preg_match('/^((?:\e\[[0-9;]*m)*[│|]?\s*(?:\e\[[0-9;]*m)*)❯\s?(.*)$/u', $line, $m)) {
                $found = $m[2];
            }
        }
        if (null === $found) {
            return null;
        }
        // Dim or grey SGR right at the start, after at most one cursor character (Claude Code draws the cursor on the first letter).
        $ghost = 1 === preg_match('/^(?:\e\[[0-9;]*m)*(?:\e\[7m.\e\[(?:0|27)m)?(?:\e\[(?:0|27)?m)*\e\[(?:[0-9;]*;)?(?:2|90|38;5;2[3-5][0-9]|38;2;1[0-9]{2};1[0-9]{2};1[0-9]{2})(?:;[0-9;]*)?m/u', $found);
        $plain = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $found);

        return ['text' => rtrim($plain), 'ghost' => $ghost];
    }

    /**
     * The agent's input box for the terminal read (FLE-359), or null when the pane has no prompt line or cannot be read.
     *
     * @return array{text: string, suggestion: bool}|null
     */
    public function agentInput(string $instanceId, Pane $pane): ?array
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane || !\in_array($instanceId, $this->listWindows(), true)) {
            return null;
        }
        $target = self::shellFor($this->panes($instanceId), $pane);
        $input = null === $target ? null : $this->readInput($target->id);

        return null === $input ? null : ['text' => $input['text'], 'suggestion' => $input['ghost']];
    }

    /**
     * The usage-limit dialog of the agent pane (FLE-368), read from a plain capture of its last 30 lines, or null when the pane
     * does not show it, does not exist or cannot be read.
     *
     * @throws WorkspaceException
     */
    public function usageLimit(string $instanceId, Pane $pane): ?UsageLimit
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane || !\in_array($instanceId, $this->listWindows(), true)) {
            return null;
        }
        $target = self::shellFor($this->panes($instanceId), $pane);
        if (null === $target) {
            return null;
        }

        return $this->usageLimitOf($target->id);
    }

    /** The same read for a pane {@see agentPane()} just found: no second lookup of the window between the check and the keys. */
    public function usageLimitOfAgent(AgentPane $pane): ?UsageLimit
    {
        return $this->usageLimitOf($pane->id);
    }

    private function usageLimitOf(string $paneId): ?UsageLimit
    {
        // The visible screen only (no -S): a dialog that was dismissed but still sits in the scrollback is not the current state.
        $result = $this->tmux(['capture-pane', '-p', '-J', '-t', $paneId]);

        return $result->isSuccess() ? UsageLimit::detect($result->stdout) : null;
    }

    /**
     * @return array{window: string, command: string, dead: bool, pid: string}|null
     */
    private function paneState(string $paneId): ?array
    {
        if (1 !== preg_match('/^%[0-9]{1,9}$/D', $paneId)) {
            return null;
        }
        $result = $this->tmux(['display-message', '-p', '-t', $paneId, "#{window_name}\t#{pane_current_command}\t#{pane_dead}\t#{pane_pid}"]);
        if (!$result->isSuccess()) {
            return null;
        }
        $f = array_pad(explode("\t", rtrim($result->stdout, "\r\n"), 4), 4, '');
        if ('' === $f[0]) {
            return null;
        }

        return ['window' => $f[0], 'command' => $f[1], 'dead' => '1' === $f[2], 'pid' => $f[3]];
    }

    /**
     * What the pane runs as far as the agent gate is concerned (FLE-352), or null when it is not an agent. A host foreground
     * command in AGENTS counts as it is. `ddev` (an agent started with `ddev exec claude ...`, which is what a Fleet agent pane
     * runs) is looked through: the instance container's terminal session must be exactly one, matching the host command, whose
     * foreground leaf processes are all agents or children of an agent. Everything else, and any failure, is null (fail closed).
     *
     * @param array{window: string, command: string, dead: bool, pid: string} $state
     *
     * @return array{command: string, display: string}|null
     */
    private function effectiveForeground(string $instanceId, array $state): ?array
    {
        if (\in_array($state['command'], self::AGENTS, true)) {
            return ['command' => $state['command'], 'display' => $state['command']];
        }
        if ('ddev' !== $state['command']) {
            return null;
        }
        try {
            $exec = $this->hostDdevExec($state['pid']);
            if (null === $exec) {
                return null;
            }
            $command = $this->containerForeground($instanceId, $exec['service'], $exec['command']);
        } catch (\Throwable) {
            return null;
        }

        return null === $command ? null : ['command' => $command, 'display' => 'ddev>'.$command];
    }

    /**
     * The `ddev exec [flags] <cmd...>` the pane's foreground process group runs on the host: its service and its command words.
     * Only a small list of flags is understood; any other flag, or any other ddev command, is null.
     *
     * @return array{service: string, command: list<string>}|null
     */
    private function hostDdevExec(string $panePid): ?array
    {
        if (1 !== preg_match('/^[0-9]{1,9}$/D', $panePid)) {
            return null;
        }
        $stat = $this->runner->run(['cat', '/proc/'.$panePid.'/stat'], null, [], null, self::TIMEOUT);
        // "pid (comm) state ppid pgrp session tty_nr tpgid ..."; comm may hold spaces and parentheses, so cut at the last ")".
        $close = strrpos($stat->stdout, ')');
        if (!$stat->isSuccess() || false === $close) {
            return null;
        }
        $rest = preg_split('/\s+/', trim(substr($stat->stdout, $close + 1))) ?: [];
        $tpgid = $rest[5] ?? '';
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/D', $tpgid)) {
            return null;
        }
        $cmdline = $this->runner->run(['cat', '/proc/'.$tpgid.'/cmdline'], null, [], null, self::TIMEOUT);
        if (!$cmdline->isSuccess()) {
            return null;
        }
        $argv = explode("\0", rtrim($cmdline->stdout, "\0"));
        if (\count($argv) < 3 || 'ddev' !== basename($argv[0]) || 'exec' !== $argv[1]) {
            return null;
        }
        $service = 'web';
        for ($i = 2, $n = \count($argv); $i < $n; ++$i) {
            $arg = $argv[$i];
            if ('--' === $arg) {
                ++$i;
                break;
            }
            if (!str_starts_with($arg, '-')) {
                break;
            }
            if ('--raw' === $arg) {
                continue;
            }
            if (1 === preg_match('/^(?:-s|--service|-d|--dir)$/D', $arg)) {
                if (!isset($argv[$i + 1])) {
                    return null;
                }
                $value = $argv[++$i];
            } elseif (1 === preg_match('/^(--service|--dir)=(.*)$/Ds', $arg, $m)) {
                $arg = $m[1];
                $value = $m[2];
            } else {
                return null;
            }
            if ('-s' === $arg || '--service' === $arg) {
                $service = $value;
            }
        }
        $command = \array_slice($argv, $i);
        // The command being run must itself be an agent: `ddev exec bash` never passes, whatever the container shows.
        if ([] === $command || !\in_array(basename($command[0]), self::AGENTS, true) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $service)) {
            return null;
        }

        return ['service' => $service, 'command' => $command];
    }

    /**
     * Looks into the container `ddev-<instance>-<service>`: the terminal sessions are the processes with a tty whose parent is 0
     * (PID 1 is the container init). Exactly one must carry the host command in its arguments; its tty's foreground process
     * group must have at least one leaf (a member without a child in the group) and every leaf must be an agent or descend,
     * within the group, from an agent (its background shells, FLE-361).
     *
     * @param list<string> $hostCommand
     */
    private function containerForeground(string $instanceId, string $service, array $hostCommand): ?string
    {
        $needle = self::normalise(implode(' ', $hostCommand));
        if ('' === $needle) {
            return null;
        }
        $result = $this->runner->run(
            ['docker', 'exec', 'ddev-'.$instanceId.'-'.$service, 'ps', '-eo', 'pid=,ppid=,pgid=,tpgid=,tty=,comm=,args='],
            null,
            [],
            null,
            self::DOCKER_TIMEOUT,
        );
        if (!$result->isSuccess()) {
            return null;
        }
        $procs = [];
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if (1 === preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(-?\d+)\s+(\S+)\s+(\S+)\s*(.*)$/Ds', $line, $m)) {
                $procs[] = ['pid' => (int) $m[1], 'ppid' => (int) $m[2], 'pgid' => (int) $m[3], 'tpgid' => (int) $m[4], 'tty' => $m[5], 'comm' => $m[6], 'args' => self::normalise($m[7])];
            }
        }
        $sessions = array_values(array_filter(
            $procs,
            static fn (array $p): bool => 0 === $p['ppid'] && 1 !== $p['pid'] && '?' !== $p['tty'] && str_contains($p['args'], $needle),
        ));
        if (1 !== \count($sessions) || $sessions[0]['tpgid'] <= 0) {
            return null;
        }
        $session = $sessions[0];
        $group = array_values(array_filter(
            $procs,
            static fn (array $p): bool => $p['pgid'] === $session['tpgid'] && $p['tty'] === $session['tty'],
        ));
        $parents = array_column($group, 'ppid');
        $leaves = array_filter($group, static fn (array $p): bool => !\in_array($p['pid'], $parents, true));
        if ([] === $leaves) {
            return null;
        }
        $byPid = array_column($group, null, 'pid');
        $command = null;
        foreach ($leaves as $leaf) {
            // FLE-361: an agent's own children (background shells, subagent commands) stay in its process group, so a leaf
            // counts when it is an agent or descends, inside the group, from one. A shell with no agent above it fails.
            $agent = self::agentAncestor($leaf, $byPid);
            if (null === $agent || (\in_array($leaf['comm'], self::SHELLS, true) && !self::runsCommand($leaf['args']))) {
                return null;
            }
            $command = $agent;
        }

        return $command;
    }

    /** Whether a shell's arguments carry `-c` (alone or in a short-option cluster such as `-lc`) before its command. */
    private static function runsCommand(string $args): bool
    {
        foreach (\array_slice(explode(' ', $args), 1) as $word) {
            if (!str_starts_with($word, '-')) {
                return false;
            }
            if (1 === preg_match('/^-[A-Za-z]*c[A-Za-z]*$/D', $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `comm` of the nearest agent among $proc and its ancestors, following ppid through the members of the foreground
     * group only (the walk stops at the first parent outside it), or null when there is none.
     *
     * @param array{pid: int, ppid: int, comm: string}             $proc
     * @param array<int, array{pid: int, ppid: int, comm: string}> $group
     */
    private static function agentAncestor(array $proc, array $group): ?string
    {
        $seen = [];
        while (!isset($seen[$proc['pid']])) {
            if (\in_array($proc['comm'], self::AGENTS, true)) {
                return $proc['comm'];
            }
            $seen[$proc['pid']] = true;
            if (!isset($group[$proc['ppid']])) {
                return null;
            }
            $proc = $group[$proc['ppid']];
        }

        return null;
    }

    /** Quotes removed and whitespace collapsed, so a command typed on the host compares with the same one inside a `bash -c`. */
    private static function normalise(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(["'", '"'], '', $s)));
    }

    /**
     * @param list<PaneInfo> $panes
     */
    private static function strictShell(array $panes, Pane $pane): ?PaneInfo
    {
        $role = Pane::Tty1 === $pane ? PaneInfo::ROLE_TTY1 : PaneInfo::ROLE_TTY2;
        foreach ($panes as $candidate) {
            if ($candidate->role === $role) {
                return $candidate;
            }
        }
        $shells = array_values(array_filter(self::shells($panes), static fn (PaneInfo $p): bool => '' === $p->role));
        if (2 !== \count($shells) || 2 !== \count(self::shells($panes))) {
            return null;
        }

        return $shells[Pane::Tty1 === $pane ? 0 : 1];
    }

    /**
     * The visible text and scrollback of tty1 or tty2 of the window (`capture-pane -p -J`, the last $lines lines), read only.
     * The sidebar and the `general` window are never captured: the pane is found by position among the window's shells,
     * and only an instance id (never `general`) names a window. Null when the window or the pane does not exist.
     *
     * @throws WorkspaceException
     */
    public function capture(string $instanceId, Pane $pane, int $lines): ?string
    {
        self::assertId($instanceId);
        if (Pane::Sidebar === $pane) {
            throw new WorkspaceException('Only tty1 or tty2 can be read, not the sidebar.');
        }
        if ($lines < 1 || $lines > 1000) {
            throw new WorkspaceException('The line count is out of range.');
        }
        if (!\in_array($instanceId, $this->listWindows(), true)) {
            return null;
        }
        $target = self::shellFor($this->panes($instanceId), $pane);
        if (null === $target) {
            return null;
        }

        return $this->must(['capture-pane', '-p', '-J', '-t', $target->id, '-S', '-'.$lines], 'capture the pane')->stdout;
    }

    /**
     * Rebuilds the window in place: every pane is respawned (fresh shells, a fresh sidebar), the tab keeps its index; a
     * missing window is created. Works for `general` too.
     *
     * @throws WorkspaceException
     */
    public function reset(string $name): void
    {
        $dir = $this->dirOf($name);
        $this->ensureSession();
        if (!\in_array($name, $this->listWindows(), true)) {
            $this->createWindow($name, $dir);

            return;
        }
        $this->heal($name, $dir);
        foreach ($this->panes($name) as $pane) {
            $args = ['respawn-pane', '-k', '-t', $pane->id, '-c', $dir];
            if ($pane->isSidebar()) {
                $args[] = $this->sidebarCommand($name);
            }
            $this->must($args, 'respawn a pane');
        }
        $this->applyLayout($name);
    }

    /**
     * The name of the window a pane (`%12`) is in, read back from tmux; null when the pane is gone.
     */
    public function windowOfPane(string $paneId): ?string
    {
        if (1 !== preg_match('/^%[0-9]{1,9}$/D', $paneId)) {
            return null;
        }
        $result = $this->tmux(['display-message', '-p', '-t', $paneId, '#{window_name}']);
        $name = trim($result->stdout);

        return $result->isSuccess() && '' !== $name ? $name : null;
    }

    /**
     * Sidebar 50 columns, the two shells equal. Called after the window changed and when the terminal was resized.
     *
     * @throws WorkspaceException
     */
    public function applyLayout(string $name): void
    {
        $panes = $this->panes($name);
        $sidebar = self::sidebarOf($panes);
        $shells = self::shells($panes);
        if ([] === $panes) {
            return;
        }
        $width = $panes[0]->windowWidth;
        if (null !== $sidebar && self::SIDEBAR_WIDTH !== $sidebar->width && $width >= self::SIDEBAR_WIDTH + 2 * self::MIN_SHELL_WIDTH) {
            $this->must(['resize-pane', '-t', $sidebar->id, '-x', (string) self::SIDEBAR_WIDTH], 'resize the sidebar');
        }
        if (\count($shells) >= 2 && $shells[0]->left !== $shells[1]->left) {
            // sidebar + border + tty1 + border + tty2 = width, so the two shells share width - 52 (width - 1 without a sidebar).
            $half = intdiv($width - (null === $sidebar ? 1 : self::SIDEBAR_WIDTH + 2), 2);
            if ($half >= self::MIN_SHELL_WIDTH) {
                $this->must(['resize-pane', '-t', $shells[0]->id, '-x', (string) $half], 'equalise the shells');
            }
        }
    }

    /**
     * Whether the sidebar is not 50 columns wide any more (the terminal was resized); false when there is no sidebar.
     */
    public function layoutDrifted(string $name): bool
    {
        $sidebar = self::sidebarOf($this->panes($name));

        return null !== $sidebar && self::SIDEBAR_WIDTH !== $sidebar->width;
    }

    /**
     * @return list<PaneInfo> left to right, then top to bottom
     */
    public function panes(string $name): array
    {
        $result = $this->tmux(['list-panes', '-t', self::window($name), '-F', self::PANE_FORMAT]);
        if (!$result->isSuccess()) {
            return [];
        }
        $panes = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $f = explode("\t", rtrim($line, "\r"), 7);
            if (\count($f) < 6 || 1 !== preg_match('/^%[0-9]+$/D', $f[0])) {
                continue;
            }
            $panes[] = new PaneInfo($f[0], (int) $f[1], (int) $f[2], (int) $f[3], (int) $f[4], $f[5], $f[6] ?? '');
        }
        usort($panes, static fn (PaneInfo $a, PaneInfo $b): int => [$a->left, $a->top] <=> [$b->left, $b->top]);

        return $panes;
    }

    /**
     * The shell command line of the sidebar pane: the env file is sourced inside the pane, so no secret is on any command line.
     */
    public function sidebarCommand(string $name): string
    {
        return $this->envShell([$this->php, $this->console, 'fleet:tmux:sidebar', '--window', $name], self::KEEP_SHELL);
    }

    private function createWindow(string $name, string $dir): void
    {
        $this->must(['new-window', '-d', '-t', '='.self::SESSION.':', '-n', $name, '-c', $dir], 'create the window');
        $this->heal($name, $dir);
    }

    /**
     * Brings a window to `[sidebar | tty1 | tty2]` without killing anything: tags it, adds a missing sidebar or second shell,
     * moves a stacked second shell beside the first (the 1.0 layout), then fixes the widths when it changed something.
     *
     * @throws WorkspaceException
     */
    private function heal(string $name, string $dir): void
    {
        $window = self::window($name);
        $this->must(['set-option', '-w', '-t', $window, '@fleet_managed', '1'], 'tag the window');
        $changed = false;

        $panes = $this->panes($name);
        if (null === self::sidebarOf($panes) && \count($panes) >= 2 && str_contains($panes[0]->command, 'fleet:tmux:sidebar')) {
            // A window made before the tags existed: its leftmost pane is the sidebar.
            $this->must(['set-option', '-p', '-t', $panes[0]->id, '@fleet_role', PaneInfo::ROLE_SIDEBAR], 'tag the sidebar');
            $panes = $this->panes($name);
        }
        $sidebar = self::sidebarOf($panes);
        $shells = self::shells($panes);

        if ([] === $shells && null !== $sidebar) {
            $this->must(['split-window', '-h', '-d', '-t', $sidebar->id, '-c', $dir], 'add a shell');
            $changed = true;
            $shells = self::shells($this->panes($name));
        }
        if (1 === \count($shells)) {
            $this->must(['split-window', '-h', '-d', '-t', $shells[0]->id, '-c', $dir], 'add the second shell');
            $changed = true;
            $shells = self::shells($this->panes($name));
        }
        if (\count($shells) >= 2 && $shells[0]->left === $shells[1]->left) {
            $this->must(['move-pane', '-h', '-d', '-s', $shells[1]->id, '-t', $shells[0]->id], 'put the shells side by side');
            $changed = true;
            $shells = self::shells($this->panes($name));
        }
        if (null === $sidebar && [] !== $shells) {
            $created = $this->must(['split-window', '-h', '-b', '-f', '-l', (string) self::SIDEBAR_WIDTH, '-d', '-P', '-F', '#{pane_id}', '-t', $shells[0]->id, '-c', $dir, $this->sidebarCommand($name)], 'add the sidebar');
            $id = trim($created->stdout);
            if (1 === preg_match('/^%[0-9]+$/D', $id)) {
                $this->must(['set-option', '-p', '-t', $id, '@fleet_role', PaneInfo::ROLE_SIDEBAR], 'tag the sidebar');
            }
            $changed = true;
        }
        if ($changed) {
            $this->applyLayout($name);
        }
        $this->tagShells($name);
    }

    /**
     * Gives the two shells of a window the stable `tty1` / `tty2` tags (left, right), so a later split or a lost sidebar cannot
     * move the address of a pane. Only a window with exactly two untagged shells is tagged; the `general` window never is.
     */
    private function tagShells(string $name): void
    {
        if (self::HOME_WINDOW === $name) {
            return;
        }
        $shells = self::shells($this->panes($name));
        if (2 !== \count($shells) || '' !== $shells[0]->role || '' !== $shells[1]->role) {
            return;
        }
        $this->must(['set-option', '-p', '-t', $shells[0]->id, '@fleet_role', PaneInfo::ROLE_TTY1], 'tag tty1');
        $this->must(['set-option', '-p', '-t', $shells[1]->id, '@fleet_role', PaneInfo::ROLE_TTY2], 'tag tty2');
    }

    /**
     * tty1 or tty2 of the panes: by tag when tagged, else by position among the shells (the 1.x layout before the tags).
     *
     * @param list<PaneInfo> $panes
     */
    private static function shellFor(array $panes, Pane $pane): ?PaneInfo
    {
        $role = Pane::Tty1 === $pane ? PaneInfo::ROLE_TTY1 : PaneInfo::ROLE_TTY2;
        foreach ($panes as $candidate) {
            if ($candidate->role === $role) {
                return $candidate;
            }
        }
        $untagged = array_values(array_filter(self::shells($panes), static fn (PaneInfo $p): bool => '' === $p->role));

        return $untagged[Pane::Tty1 === $pane ? 0 : 1] ?? null;
    }

    private function dirOf(string $name): string
    {
        if (self::HOME_WINDOW === $name) {
            return $this->paths->home();
        }
        self::assertId($name);

        return $this->paths->instanceDir($name);
    }

    /**
     * @param list<string> $argv
     */
    private function envShell(array $argv, string $script = self::ENV_SHELL): string
    {
        return implode(' ', array_map(escapeshellarg(...), ['sh', '-c', $script, $this->envFile, ...$argv]));
    }

    /**
     * @param list<PaneInfo> $panes
     */
    private static function sidebarOf(array $panes): ?PaneInfo
    {
        foreach ($panes as $pane) {
            if ($pane->isSidebar()) {
                return $pane;
            }
        }

        return null;
    }

    /**
     * @param list<PaneInfo> $panes
     *
     * @return list<PaneInfo> the shell panes, left to right
     */
    private static function shells(array $panes): array
    {
        return array_values(array_filter($panes, static fn (PaneInfo $pane): bool => !$pane->isSidebar()));
    }

    /**
     * @param list<string> $args
     */
    private function tmux(array $args): CommandResult
    {
        return $this->runner->run(['tmux', '-L', self::SOCKET, ...$args], null, [], null, self::TIMEOUT);
    }

    /**
     * @param list<string> $args
     */
    private function must(array $args, string $what): CommandResult
    {
        $result = $this->tmux($args);
        if (!$result->isSuccess()) {
            throw new WorkspaceException(\sprintf('tmux could not %s (exit %d): %s', $what, $result->exitCode, trim($result->stderr)));
        }

        return $result;
    }

    /** An exact-match target: `=fleet:=<id>` never matches a window whose name merely starts with the id. */
    private static function window(string $name): string
    {
        return '='.self::SESSION.':='.$name;
    }

    private static function assertId(string $instanceId): void
    {
        if (1 !== preg_match(FleetPaths::EXISTING_INSTANCE_ID_PATTERN, $instanceId)) {
            throw new WorkspaceException('That is not an instance id.');
        }
    }
}
