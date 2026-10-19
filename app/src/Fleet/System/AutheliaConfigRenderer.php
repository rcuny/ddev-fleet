<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\FleetPaths;
use Twig\Environment;

/**
 * Renders Authelia's main configuration (installed as root through `apply authelia`). The secrets (session secret,
 * storage encryption key, reset-password JWT secret) are deliberately absent: the installer supplies them through
 * AUTHELIA_*_FILE variables. users.yml is the app's own file; this configuration changes when the access rules do
 * (one per project, from AccessRuleSource) or the notifier settings (AutheliaNotifier).
 *
 * The self-service password reset is enabled only when an SMTP notifier is configured: without one the reset link
 * could not be delivered, so the flow stays disabled and `fleet user:reset-password` is the recovery path.
 */
final readonly class AutheliaConfigRenderer
{
    /** Where Authelia keeps its SQLite database and notification file (owned by the authelia user). */
    public const string STATE_DIR = '/var/lib/authelia';

    public function __construct(
        private Environment $twig,
        private FleetPaths $paths,
        private AdminGroup $admins,
        private ?AccessRuleSource $rules = null,
        private ?AutheliaNotifier $notifier = null,
    ) {
    }

    public function render(HostSettings $host): string
    {
        $domain = ConfigValue::domain($host->domain());
        $smtp = $this->notifier?->smtp();

        return $this->twig->render('system/authelia-configuration.twig', [
            'domain' => $domain,
            // Last rule: the hosts of projects without a rule of their own stay admin-only (Authelia is first match).
            'admin_group' => ConfigValue::group($this->admins->name()),
            'rules' => $this->projectRules($domain),
            'smtp' => $smtp,
            'reset_enabled' => null !== $smtp,
            'address' => CaddyfileRenderer::AUTHELIA,
            'users_file' => ConfigValue::path('The Authelia users file', $this->paths->usersFile()),
            'state_dir' => self::STATE_DIR,
        ]);
    }

    /**
     * @return list<array{regex: string, subjects: list<string>}>
     */
    private function projectRules(string $domain): array
    {
        $hostPattern = str_replace('.', '\\.', $domain);

        $rules = [];
        foreach ($this->rules?->rules() ?? [] as $rule) {
            $subjects = ['group:'.ConfigValue::group($rule->adminGroup)];
            foreach ($rule->users as $username) {
                $subjects[] = 'user:'.ConfigValue::username($username);
            }

            // `<project>--<label>.<domain>` and the aliases `<hostname>-<project>--<label>.<domain>`, with the project's
            // own hostnames listed (never a free prefix: "x" must not match the alias of project "blog-x"). The label
            // is one DNS label. Names cannot contain "--" or ".", so the project part is unambiguous, and the domain is
            // validated lowercase letters, digits, dots and dashes.
            $prefix = '';
            if ([] !== $rule->hostnames) {
                $prefix = '(?:(?:'.implode('|', array_map(ConfigValue::hostnameLabel(...), $rule->hostnames)).')-)?';
            }

            $rules[] = [
                'regex' => '^'.$prefix.ConfigValue::projectName($rule->project).'--[a-z0-9-]+\\.'.$hostPattern.'$',
                'subjects' => array_values(array_unique($subjects)),
            ];
        }

        return $rules;
    }
}
