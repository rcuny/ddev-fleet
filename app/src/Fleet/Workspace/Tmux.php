<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The persistent `fleet` tmux session of the agent workspace: one window per instance, named after the instance id, with
 * the sidebar, tty1 and tty2 panes. Runs as whoever runs the app (the fleet user); the tmux server lives on its own socket
 * (`tmux -L fleet`) and in the cgroup of fleet-tmux.service, so restarting php-fpm or the workers leaves it alone.
 *
 * Every call is an argv array through the CommandRunner. The panes are plain shells: this service never types a tty command
 * by itself, a caller does with sendKeys() (the deploy step, later).
 */
final readonly class Tmux
{
    public const string SESSION = 'fleet';
    public const string SOCKET = 'fleet';
    /** The window the session starts with: a shell in FLEET_HOME. Instance ids contain `--`, so it cannot collide. */
    public const string HOME_WINDOW = 'host';
    public const int COMMAND_MAX = 4096;
    public const float TIMEOUT = 15.0;

    public function __construct(
        private CommandRunner $runner,
        private FleetPaths $paths,
        #[Autowire('%kernel.project_dir%/bin/console')]
        private string $console,
        private string $php = '/usr/bin/php8.4',
    ) {
    }

    public function hasSession(): bool
    {
        return $this->tmux(['has-session', '-t', '='.self::SESSION])->isSuccess();
    }

    /**
     * Creates the session when it does not exist. Returns whether it was created.
     */
    public function ensureSession(): bool
    {
        if ($this->hasSession()) {
            return false;
        }
        $this->must(['new-session', '-d', '-s', self::SESSION, '-n', self::HOME_WINDOW, '-c', $this->paths->home()], 'create the session');

        return true;
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
     * Creates the window of an instance (sidebar, tty1, tty2, all in its directory) unless it exists. Returns whether it was created.
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
            return false;
        }

        $window = self::window($instanceId);
        $this->must(['new-window', '-d', '-t', '='.self::SESSION.':', '-n', $instanceId, '-c', $dir, $this->php, $this->console, 'fleet:tmux:sidebar', '--window', $instanceId], 'create the window');
        $this->must(['split-window', '-h', '-d', '-t', $window.'.'.Pane::Sidebar->value, '-c', $dir], 'split the window');
        $this->must(['split-window', '-v', '-d', '-t', $window.'.'.Pane::Tty1->value, '-c', $dir], 'split the window');

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
     * Types $command literally into tty1 or tty2 of the instance's window, then Enter. The caller builds the command line;
     * a control character (a newline would run a second command, an escape drives the terminal) is refused.
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

        $target = self::window($instanceId).'.'.$pane->value;
        $this->must(['send-keys', '-t', $target, '-l', '--', $command], 'send the keys');
        $this->must(['send-keys', '-t', $target, 'Enter'], 'send Enter');
    }

    /**
     * Throws the window away and builds it again, with fresh shells.
     *
     * @throws WorkspaceException
     */
    public function reset(string $instanceId): void
    {
        self::assertId($instanceId);
        $this->closeWindow($instanceId);
        $this->ensureWindow($instanceId, $this->paths->instanceDir($instanceId));
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
    private static function window(string $instanceId): string
    {
        return '='.self::SESSION.':='.$instanceId;
    }

    private static function assertId(string $instanceId): void
    {
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $instanceId)) {
            throw new WorkspaceException('That is not an instance id.');
        }
    }
}
