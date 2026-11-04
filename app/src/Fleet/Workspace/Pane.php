<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * The three panes of a window, side by side: the sidebar on the left (50 columns), tty1 in the middle, tty2 on the right.
 * The values are the tmux pane indexes once the sidebar is alive (`fleet:<window>.2` is the Claude pane); Tmux itself
 * addresses a pane by its role and left-to-right position, so a window that lost its sidebar still routes tty1 and tty2
 * right.
 */
enum Pane: int
{
    case Sidebar = 0;
    case Tty1 = 1;
    case Tty2 = 2;
}
