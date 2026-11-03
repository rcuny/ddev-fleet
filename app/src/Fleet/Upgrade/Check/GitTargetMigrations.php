<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Release;

/** Lists `app/migrations` of the release's commit in the install checkout; null when the checkout lacks the commit. */
final readonly class GitTargetMigrations implements TargetMigrations
{
    public function __construct(private CommandRunner $runner, private string $checkoutDir)
    {
    }

    public function at(Release $release): ?array
    {
        try {
            $result = $this->runner->run(['git', '-C', $this->checkoutDir, 'ls-tree', '--name-only', $release->sha, 'app/migrations/'], timeout: 15.0);
        } catch (\Throwable) {
            return null;
        }
        if (!$result->isSuccess()) {
            return null;
        }
        $names = [];
        foreach (explode("\n", $result->stdout) as $line) {
            if (1 === preg_match('~^app/migrations/(Version\d+)\.php$~', trim($line), $m)) {
                $names[] = $m[1];
            }
        }

        return $names;
    }
}
