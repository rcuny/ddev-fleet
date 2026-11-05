<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

use Symfony\Component\Process\Process;

/**
 * A Process that does not kill its child when the PHP object is destroyed (FLE-209). Symfony's destructor calls
 * stop(0) (SIGTERM, then SIGKILL): with it, the worker leaving (`--time-limit`, `systemctl restart`, an upgrade) would kill
 * every job it started. The worker unit has KillMode=process, so systemd leaves the children alone too; they finish on
 * their own and are counted again through their `running` job row.
 */
final class DetachedProcess extends Process
{
    public function __destruct()
    {
    }
}
