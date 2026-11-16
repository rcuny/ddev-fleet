<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Jobs\SecretRegistry;
use App\Fleet\System\ConfigValue;

/**
 * Whether and where an instance's Mailpit is reachable from the browser (FLE-344).
 *
 * Mailpit always runs inside the DDEV web container; this only decides about the Caddy route. The host is
 * `mailpit--<instance id>.<domain>`: one DNS label under the same wildcard as the instance, so it needs no certificate
 * of its own in `ovh_dns` mode. No collision with an instance host is possible because the project name `mailpit` is
 * reserved (ResourceName::PROJECT_RESERVED), so no instance id starts with `mailpit--`. The label is at most 63
 * characters: an instance id is allowed 59, so a long id gets no Mailpit route (`fits()`), and the instance page says so.
 *
 * The route sits behind the instance's own gate. Caddy adds the Mailpit basic-auth header upstream, so a user signs in
 * once (the container keeps MP_UI_AUTH, FLE-300, as defence in depth).
 */
final readonly class MailpitExposure
{
    public const string PREFIX = 'mailpit--';

    public function __construct(
        private TemplateRepository $templates,
        private InstanceIsolation $isolation,
        private InstanceHostnames $hostnames,
        private SecretRegistry $registry,
    ) {
    }

    /** Whether the instance's template switched the option on (a missing template counts as off). */
    public function enabled(Instance $instance, Project $project): bool
    {
        return true === $this->templates->find($project, $instance->templateName())?->hasMailpit();
    }

    /** Whether `mailpit--<id>` is one valid DNS label. */
    public static function fits(string $instanceId): bool
    {
        return \strlen(self::PREFIX.$instanceId) <= InstanceNaming::DNS_LABEL_MAX;
    }

    public function host(string $instanceId): string
    {
        return self::PREFIX.$instanceId.'.'.$this->hostnames->domain();
    }

    public function url(string $instanceId): string
    {
        return 'https://'.$this->host($instanceId);
    }

    /**
     * What the Caddyfile needs for the instance's Mailpit site, or null when there is none (option off, or the host
     * label would be too long).
     *
     * @return array{host: string, upstream_host: string, authorization: string}|null `authorization` is the `Basic …` header value
     */
    public function site(Instance $instance, Project $project): ?array
    {
        if (!$this->enabled($instance, $project) || !self::fits($instance->id())) {
            return null;
        }
        $credentials = $this->isolation->mailpitCredentials($instance->id());
        $this->registry->register($credentials['password']);
        $this->registry->register(base64_encode($credentials['user'].':'.$credentials['password']));

        return [
            'host' => ConfigValue::domain($this->host($instance->id())),
            // The ddev router picks the project by Host, so Mailpit is asked for under the instance's own name.
            'upstream_host' => ConfigValue::domain($this->hostnames->primary($instance->id())),
            'authorization' => 'Basic '.base64_encode($credentials['user'].':'.$credentials['password']),
        ];
    }
}
