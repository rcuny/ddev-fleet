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

    /**
     * Authelia's regulation, rendered explicitly (its defaults are the same, but a ban the operator cannot read in
     * the configuration is a ban they cannot explain). `max_retries` failed sign-ins within `find_time` ban the user
     * for `ban_time`; every attempt made while banned extends the ban. A password reset does not lift it.
     */
    public const int MAX_RETRIES = 3;
    public const string FIND_TIME = '2m';
    public const string BAN_TIME = '5m';
    public const int BAN_MINUTES = 5;

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
            'max_retries' => self::MAX_RETRIES,
            'find_time' => self::FIND_TIME,
            'ban_time' => self::BAN_TIME,
        ]);
    }

    /**
     * What to tell the operator after a password reset: Authelia keeps no way to lift a regulation ban that
     * `authelia storage` supports (`bans user revoke` covers only manual bans), so the ban simply has to run out.
     */
    public static function banNotice(string $username): string
    {
        return \sprintf(
            'Authelia may still block "%s" for up to %d minutes after failed sign-ins (%d failures within %s); wait before trying, and do not retry meanwhile: each attempt extends the ban.',
            $username,
            self::BAN_MINUTES,
            self::MAX_RETRIES,
            self::FIND_TIME,
        );
    }

    /**
     * @return list<array{regex: string, subjects: list<string>}>
     */
    private function projectRules(string $domain): array
    {
        $hostPattern = str_replace('.', '\\.', $domain);

        $all = $this->rules?->rules() ?? [];
        $projects = array_flip(array_map(static fn (AccessRule $rule): string => $rule->project, $all));

        $rules = [];
        foreach ($all as $rule) {
            $subjects = ['group:'.ConfigValue::group($rule->adminGroup)];
            foreach ($rule->users as $username) {
                $subjects[] = 'user:'.ConfigValue::username($username);
            }

            // `<project>--<label>.<domain>` and the aliases `<hostname>-<project>--<label>.<domain>`, with the project's
            // own hostnames listed (never a free prefix: "x" must not match the alias of project "blog-x"). The label
            // is one DNS label. Names cannot contain "--" or ".", so the project part is unambiguous, and the domain is
            // validated lowercase letters, digits, dots and dashes.
            // The alias "<hostname>-<project>" of one project can spell another project's own name ("x" + "blog" vs the
            // project "x-blog"): the host string is the same, and no regex can tell them apart (RE2 has no lookahead).
            // That alias is left out, so the other project's hosts never match this rule (deny by default).
            $hostnames = array_filter(
                $rule->hostnames,
                static fn (string $hostname): bool => !isset($projects[$hostname.'-'.$rule->project]),
            );
            $prefix = '';
            if ([] !== $hostnames) {
                $prefix = '(?:(?:'.implode('|', array_map(ConfigValue::hostnameLabel(...), $hostnames)).')-)?';
            }

            $rules[] = [
                'regex' => '^'.$prefix.ConfigValue::projectName($rule->project).'--[a-z0-9-]+\\.'.$hostPattern.'$',
                'subjects' => array_values(array_unique($subjects)),
            ];
        }

        return $rules;
    }
}
