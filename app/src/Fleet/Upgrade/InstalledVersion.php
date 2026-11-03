<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Shell\CommandRunner;

/**
 * What this host runs. The installer writes the tag (or `main@<sha7>` / `<ref>@<sha7>` when it installed a branch) to
 * the version file; without it the checkout's exact tag is used; outside a host (dev, tests) the answer is `dev`.
 */
final readonly class InstalledVersion
{
    public const string DEV = 'dev';

    public function __construct(
        private CommandRunner $runner,
        private string $versionFile,
        private string $checkoutDir,
    ) {
    }

    /** The raw text: `v1.2.0`, `main@abc1234` or `dev`. */
    public function raw(): string
    {
        $fromFile = is_readable($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';
        if ('' !== $fromFile && 1 === preg_match('/^[A-Za-z0-9._\/@-]{1,100}$/D', $fromFile)) {
            return $fromFile;
        }

        return $this->fromCheckout() ?? self::DEV;
    }

    /** The semantic version, or null when the host runs a branch or this is a development tree. */
    public function version(): ?Version
    {
        return Version::tryParse($this->raw());
    }

    private function fromCheckout(): ?string
    {
        if (!is_dir($this->checkoutDir.'/.git')) {
            return null;
        }
        try {
            $result = $this->runner->run(['git', '-C', $this->checkoutDir, 'describe', '--tags', '--exact-match'], timeout: 5.0);
        } catch (\Throwable) {
            return null;
        }
        $tag = trim($result->stdout);

        return $result->isSuccess() && null !== Version::tryParse($tag) ? $tag : null;
    }
}
