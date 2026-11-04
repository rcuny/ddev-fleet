<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Shell\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `fleet:upgrade:verify`: is the host healthy on the new version? Run by the root helper after the installer, in a fresh
 * process (so it is the new code that answers). Critical steps fail the upgrade (exit 5); the audit row is best effort.
 *
 *  - health      GET <DEFAULT_URI>/health answers 2xx
 *  - authelia    the Authelia portal answers on its loopback port
 *  - version     the installed-version file names the target
 *  - checkout    the install checkout is at the commit the plan resolved (a moved tag is caught here)
 *  - migrations  the database has no pending migration
 *  - workers     the worker and status units are active
 *  - audit       a `host.upgrade` audit row was written (warning only)
 */
final readonly class UpgradeVerifier implements UpgradeVerification
{
    public const string AUTHELIA_HEALTH = 'http://127.0.0.1:9091/api/health';
    /** @var list<string> */
    public const array UNITS = ['fleet-worker@1', 'fleet-status'];

    public function __construct(
        private CommandRunner $runner,
        private InstalledVersion $installed,
        private PendingMigrations $migrations,
        private AuditLog $audit,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $defaultUri,
        #[Autowire('%kernel.project_dir%/..')]
        private string $checkoutDir,
    ) {
    }

    public function verify(string $from, string $targetTag, ?string $targetSha, string $actor): VerifyReport
    {
        $results = [
            $this->http('health', rtrim($this->defaultUri, '/').'/health'),
            $this->http('authelia', self::AUTHELIA_HEALTH),
            $this->version($targetTag),
            $this->checkout($targetSha),
            $this->migrations(),
            $this->units(),
        ];
        $results[] = $this->audit($from, $targetTag, $actor);

        return new VerifyReport($results);
    }

    private function http(string $name, string $url): VerifyResult
    {
        try {
            $ok = $this->runner->run(['curl', '-fsS', '--max-time', '10', '-o', '/dev/null', $url], timeout: 15.0)->isSuccess();
        } catch (\Throwable) {
            $ok = false;
        }

        return new VerifyResult($name, $ok, $ok ? 'answers' : 'does not answer at '.$url);
    }

    private function version(string $targetTag): VerifyResult
    {
        $raw = $this->installed->raw();

        return new VerifyResult('version', $raw === $targetTag, $raw === $targetTag ? $raw : \sprintf('installed-version is "%s", expected "%s"', $raw, $targetTag));
    }

    private function checkout(?string $sha): VerifyResult
    {
        if (null === $sha) {
            return new VerifyResult('checkout', true, 'no commit to compare with', false);
        }
        try {
            $head = trim($this->runner->run(['git', '-C', $this->checkoutDir, 'rev-parse', 'HEAD'], timeout: 10.0)->stdout);
        } catch (\Throwable) {
            $head = '';
        }

        return new VerifyResult('checkout', $head === $sha, $head === $sha ? 'at '.substr($sha, 0, 7) : \sprintf('at "%s", the plan resolved %s', substr($head, 0, 7), substr($sha, 0, 7)));
    }

    private function migrations(): VerifyResult
    {
        try {
            $pending = $this->migrations->count();
        } catch (\Throwable $e) {
            return new VerifyResult('migrations', false, 'cannot read the migration status: '.$e->getMessage());
        }

        return new VerifyResult('migrations', 0 === $pending, 0 === $pending ? 'none pending' : $pending.' pending');
    }

    private function units(): VerifyResult
    {
        $inactive = [];
        foreach (self::UNITS as $unit) {
            try {
                $active = $this->runner->run(['systemctl', 'is-active', '--quiet', $unit], timeout: 10.0)->isSuccess();
            } catch (\Throwable) {
                $active = false;
            }
            if (!$active) {
                $inactive[] = $unit;
            }
        }

        return new VerifyResult('workers', [] === $inactive, [] === $inactive ? implode(', ', self::UNITS).' active' : implode(', ', $inactive).' not active');
    }

    private function audit(string $from, string $targetTag, string $actor): VerifyResult
    {
        try {
            $this->audit->record($actor, AuditLog::HOST_UPGRADE, $from.' -> '.$targetTag);
            $rows = $this->audit->latest(1, AuditLog::HOST_UPGRADE);
            $ok = [] !== $rows;
        } catch (\Throwable $e) {
            return new VerifyResult('audit', false, 'no audit row: '.$e->getMessage(), false);
        }

        return new VerifyResult('audit', $ok, $ok ? 'host.upgrade recorded' : 'the audit row is missing', false);
    }
}
