<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Host\RebootSignal;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Projects\Refused;

/**
 * The text of the sidebar pane (50 columns): the title, the reboot banner, every instance with a state glyph and its live
 * branch (the current window reverse-highlighted), the state and URL of the current instance, and the key hints.
 * ANSI styling only (reverse, dim, bold, 16-colour foregrounds), so the pane and a test show exactly what is returned.
 * Instances are coloured by state as in the 0.x sidebar; escape codes never count towards the 46 visible columns.
 */
final readonly class SidebarRenderer
{
    /** The pane is 50 columns; the text keeps a margin of two on each side. */
    public const int WIDTH = 46;

    private const string REVERSE = "\e[7m";
    private const string DIM = "\e[2m";
    private const string BOLD = "\e[1m";
    private const string RESET = "\e[0m";
    private const string GREEN = "\e[32m";
    private const string GREY = "\e[90m";
    private const string CYAN = "\e[36m";
    private const string RED = "\e[31m";
    private const string YELLOW_BOLD = "\e[1;33m";

    public function __construct(
        private InstanceRepository $instances,
        private InstanceHostnames $hostnames,
        private GitClient $git,
        private FleetPaths $paths,
        private RebootSignal $reboot,
    ) {
    }

    /**
     * @param string $window the current window: `general` or an instance id
     *
     * @throws WorkspaceException when it is neither
     */
    public function render(string $window, ?SidebarGitCache $cache = null, ?int $now = null): string
    {
        if (Tmux::HOME_WINDOW !== $window && 1 !== preg_match(FleetPaths::EXISTING_INSTANCE_ID_PATTERN, $window)) {
            throw new WorkspaceException('That is not an instance id.');
        }
        $cache ??= new SidebarGitCache();
        $now ??= time();

        $lines = [self::BOLD.'FLEET'.self::RESET];
        $status = $this->reboot->status();
        if ($status->required) {
            $lines[] = self::YELLOW_BOLD.'! reboot required'.self::RESET;
        }
        $lines[] = '';
        $lines[] = $this->row('○', Tmux::HOME_WINDOW, Tmux::HOME_WINDOW === $window, self::DIM);

        $all = $this->instances->all();
        foreach ($all as $instance) {
            $lines[] = $this->row(self::glyph($instance->state()), $instance->id(), $instance->id() === $window, self::colour($instance->state()));
            $git = $cache->get($instance->id(), $now, fn (): array => $this->live($instance));
            $text = '' === $git['branch'] ? '' : $git['branch'].('' === $git['head'] ? '' : ' @ '.$git['head']);
            foreach (self::wrap($text, self::WIDTH - 2) as $part) {
                $lines[] = '  '.self::GREY.$part.self::RESET;
            }
        }

        $current = Tmux::HOME_WINDOW === $window ? null : $this->instances->find($window);
        if (Tmux::HOME_WINDOW !== $window) {
            $lines[] = '';
            if (null === $current) {
                $lines[] = 'This instance no longer exists.';
            } else {
                try {
                    $url = $this->hostnames->siteUrl($window);
                } catch (Refused) {
                    $url = '(the host is not set up yet)';
                }
                foreach ([\sprintf('state    %s', $current->state()->value), \sprintf('url      %s', $url), \sprintf('project  %s', $current->project()->name())] as $detail) {
                    foreach (self::wrap($detail, self::WIDTH, '         ') as $part) {
                        $lines[] = $part;
                    }
                }
            }
        }

        array_push(
            $lines,
            '',
            self::DIM.'^b w  switch tab',
            '^b R  reset tab',
            '^b d  detach',
            '^b arrows  move pane',
            '^b z  zoom',
            'reattach: fleet tmux'.self::RESET,
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{branch: string, head: string}
     */
    private function live(Instance $instance): array
    {
        $dir = $this->paths->instanceDir($instance->id());
        $branch = '';
        foreach ($this->git->localBranches($dir) as $candidate) {
            if ($candidate['current']) {
                $branch = $candidate['name'];
            }
        }
        $submodule = $instance->project()->displaySubmoduleBranch();
        if (null !== $submodule && '' !== $submodule) {
            $inner = $this->git->localBranches($dir.'/'.$submodule);
            foreach ($inner as $candidate) {
                if ($candidate['current']) {
                    $branch = $candidate['name'];
                }
            }
            $head = $this->git->head($dir.'/'.$submodule);
        } else {
            $head = $this->git->head($dir);
        }

        return ['branch' => '' === $branch ? $instance->branch() : $branch, 'head' => $head];
    }

    private function row(string $glyph, string $name, bool $current, string $style): string
    {
        $text = self::clip($glyph.' '.$name, self::WIDTH);

        // the current row is plain reverse: readable on any background
        return $current ? self::REVERSE.mb_str_pad($text, self::WIDTH).self::RESET : ('' === $style ? $text : $style.$text.self::RESET);
    }

    private static function colour(InstanceState $state): string
    {
        return match ($state) {
            InstanceState::Running => self::GREEN,
            InstanceState::Stopped => self::GREY,
            InstanceState::Deploying => self::CYAN,
            InstanceState::Failed, InstanceState::Destroying => self::RED,
            InstanceState::Unknown => '',
        };
    }

    private static function glyph(InstanceState $state): string
    {
        return match ($state) {
            InstanceState::Running => '●',
            InstanceState::Stopped => '○',
            InstanceState::Deploying => '•',
            InstanceState::Failed, InstanceState::Destroying => '!',
            InstanceState::Unknown => '?',
        };
    }

    private static function clip(string $text, int $width): string
    {
        return mb_strlen($text) > $width ? mb_substr($text, 0, $width - 1).'…' : $text;
    }

    /**
     * @return list<string> the text cut into lines of at most $width characters (hard cut, branch names have no spaces)
     */
    private static function wrap(string $text, int $width, string $indent = ''): array
    {
        if ('' === $text) {
            return [];
        }
        $lines = [];
        $room = $width;
        $prefix = '';
        while ('' !== $text) {
            $lines[] = $prefix.mb_substr($text, 0, $room);
            $text = mb_substr($text, $room);
            $prefix = $indent;
            $room = $width - mb_strlen($indent);
        }

        return $lines;
    }
}
