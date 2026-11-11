<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Ssh\AtomicFile;

/**
 * FLE-313: DDEV's web healthcheck (`/healthcheck.sh`) curls Mailpit on 127.0.0.1:8025 without credentials. Once Fleet sets
 * `MP_UI_AUTH` (FLE-300) Mailpit answers 401 there, the web container never becomes healthy and every `ddev start` times out.
 *
 * Fleet writes a web-build fragment that points that probe at Mailpit's `/livez`, which stays unauthenticated and reveals
 * nothing, so the basic auth on the UI and API stays in force (audit finding F5). No credential is baked into the image.
 *
 * Fail safe: when `/healthcheck.sh` does not contain the probe (a DDEV change), the build step prints a warning and succeeds;
 * it never breaks the build. DDEV rebuilds the web image when a web-build file changes, so a stopped instance picks it up on
 * its next start.
 */
final class MailpitHealthcheckFile
{
    public const string RELATIVE_PATH = '.ddev/web-build/Dockerfile.fleet-mailpit-healthcheck';

    public const string MARKER = '# fleet-managed: Mailpit healthcheck behind basic auth (FLE-313)';

    private function __construct()
    {
    }

    public static function render(): string
    {
        return <<<'DOCKERFILE'
            # fleet-managed: Mailpit healthcheck behind basic auth (FLE-313)
            # Mailpit requires basic auth, DDEV's healthcheck calls it without credentials. /livez needs none.
            RUN if [ -f /healthcheck.sh ] && grep -q '127\.0\.0\.1:8025' /healthcheck.sh; then \
                  sed -i -E '/127\.0\.0\.1:8025/ s#127\.0\.0\.1:8025(/livez|/)?([^A-Za-z0-9/]|$)#127.0.0.1:8025/livez\2#' /healthcheck.sh; \
                else \
                  echo 'WARNING: fleet: the Mailpit probe was not found in /healthcheck.sh, left unchanged (FLE-313)'; \
                fi
            DOCKERFILE."\n";
    }

    /**
     * @param \Closure(string): void $warn receives a line for the job log
     *
     * @return string|null the path written, relative to $dir; null when there is no `.ddev` or the file could not be placed
     */
    public static function write(string $dir, \Closure $warn): ?string
    {
        $ddev = $dir.'/.ddev';
        if (!is_dir($ddev) || is_link($ddev)) {
            return null;
        }
        $build = $ddev.'/web-build';
        if (is_link($build) || (!is_dir($build) && !@mkdir($build, 0o755) && !is_dir($build))) {
            $warn('WARNING: isolation: the Mailpit healthcheck fragment was not written (.ddev/web-build is not a usable directory); ddev start may time out.');

            return null;
        }
        $path = $dir.'/'.self::RELATIVE_PATH;
        if (is_link($path)) {
            @unlink($path);
        }
        $contents = self::render();
        if (is_file($path) && @file_get_contents($path) === $contents) {
            return self::RELATIVE_PATH;
        }
        AtomicFile::write($path, $contents, 0o644);

        return self::RELATIVE_PATH;
    }
}
