<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Makes a Drupal checkout trust the fleet host names: `settings.local.php` (trusted host pattern, project overrides)
 * and `services.fleet.yml` (an empty session cookie domain, since a project may pin its own dev host name). Nothing is
 * written for a project without `<docroot>/sites/default`. The docroot comes from the repository, so the target is
 * checked to stay inside the checkout.
 */
final readonly class DrupalSettingsWriter
{
    /**
     * @return list<string> the paths written, relative to $dir
     */
    public function write(string $dir, string $domain): array
    {
        $docroot = $this->docroot($dir);
        $relative = ('' === $docroot ? '' : $docroot.'/').'sites/default';
        $target = $dir.'/'.$relative;
        if (!is_dir($target) || is_link($target)) {
            return [];
        }
        $root = realpath($dir);
        $real = realpath($target);
        if (false === $root || false === $real || !str_starts_with($real.'/', $root.'/')) {
            return [];
        }

        $escaped = str_replace('.', '\\.', $domain);
        $settings = "<?php\n"
            ."// fleet-managed: trust this instance's fleet hostname(s).\n"
            ."\$settings['trusted_host_patterns'][] = '^.+\\.{$escaped}\$';\n"
            ."// fleet-managed: neutralise any hardcoded session cookie_domain so login cookies\n"
            ."// are set for the current fleet host (services.fleet.yml is appended last, so it\n"
            ."// overrides the project's / DDEV's services container yamls).\n"
            ."\$settings['container_yamls'][] = \$app_root . '/' . \$site_path . '/services.fleet.yml';\n"
            ."// fleet-managed: load the project's own (fleet-agnostic) overrides if present.\n"
            ."if (file_exists(__DIR__ . '/settings.project.php')) {\n"
            ."    include __DIR__ . '/settings.project.php';\n"
            ."}\n";
        $services = "parameters:\n  session.storage.options:\n    cookie_domain: ''\n";

        AtomicFile::write($real.'/settings.local.php', $settings, 0o640);
        AtomicFile::write($real.'/services.fleet.yml', $services, 0o640);

        return [$relative.'/settings.local.php', $relative.'/services.fleet.yml'];
    }

    private function docroot(string $dir): string
    {
        $file = $dir.'/.ddev/config.yaml';
        if (!is_file($file)) {
            return '';
        }
        try {
            $config = Yaml::parseFile($file);
        } catch (\Throwable) {
            return '';
        }
        $docroot = \is_array($config) ? ($config['docroot'] ?? '') : '';
        if (!\is_string($docroot)) {
            return '';
        }
        $docroot = trim($docroot, '/');

        // A docroot is a relative path inside the checkout: anything else is ignored.
        return 1 === preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/D', $docroot) && !\in_array('..', explode('/', $docroot), true) ? $docroot : '';
    }
}
