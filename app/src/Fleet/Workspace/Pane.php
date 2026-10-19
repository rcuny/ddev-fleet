<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * The three panes of an instance window, by tmux pane index: the sidebar on the left, tty1 and tty2 stacked on the right.
 */
enum Pane: int
{
    case Sidebar = 0;
    case Tty1 = 1;
    case Tty2 = 2;
}
