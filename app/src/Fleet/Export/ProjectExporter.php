<?php

declare(strict_types=1);

namespace App\Fleet\Export;

use App\Domain\Port\NamedPort;
use App\Domain\Project\Project;
use App\Domain\Schedule\Schedule;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;

/**
 * Writes one project, with the pieces that belong to it, as a versioned export document (see ExportFormat): its
 * settings, templates, the catalogue ports it uses, its webhook endpoints with their rules, and the schedules that
 * target its instances (by label). Left out on purpose: ids, timestamps, instance state, assets, webhook secrets and
 * URLs (a new host issues its own), and every secret unless an operator passphrase seals them.
 */
final readonly class ProjectExporter
{
    public function __construct(
        private ProjectService $projects,
        private TemplateRepository $templates,
        private WebhookRepository $webhooks,
        private ScheduleRepository $schedules,
        private SecretStore $secrets,
        private PassphraseSealer $sealer,
        private AuditLog $audit,
    ) {
    }

    /**
     * The file text. $passphrase seals the project's managed secrets into the file; null leaves them out.
     *
     * @throws \App\Fleet\Projects\NotFound when the project does not exist
     * @throws InvalidExport                when the passphrase is refused
     */
    public function export(string $name, #[\SensitiveParameter] ?string $passphrase, string $by): string
    {
        $project = $this->projects->get($name);
        $document = $this->document($project);
        if (null !== $passphrase) {
            $document['secrets'] = $this->sealer->seal(['project' => $project->name(), 'secrets' => $this->secretValues($project)], $passphrase);
        }
        $text = ExportFormat::encode($document);
        $this->audit->record($by, AuditLog::PROJECT_EXPORT, $project->name());

        return $text;
    }

    /**
     * The document without secrets. The importer compares a file with it to tell update from unchanged.
     *
     * @return array<string, mixed>
     */
    public function document(Project $project): array
    {
        $ports = $project->ports();

        return [
            'format' => ExportFormat::NAME,
            'version' => ExportFormat::VERSION,
            'project' => [
                'name' => $project->name(),
                'git' => $project->git(),
                'default_branch' => $project->defaultBranch(),
                'default_template' => $project->defaultTemplate(),
                'additional_hostnames' => $project->additionalHostnames(),
                'ports' => array_map(static fn (NamedPort $port): string => $port->name(), $ports),
                'git_bot' => [
                    'mode' => $project->gitBotMode()->value,
                    'name' => $project->gitBotName(),
                    'email' => $project->gitBotEmail(),
                ],
                'issue_id_regexp' => $project->issueIdRegexp(),
                'display_submodule_branch' => $project->displaySubmoduleBranch(),
                'typesense' => $project->typesense(),
            ],
            'ports' => array_map(static fn (NamedPort $port): array => [
                'name' => $port->name(),
                'public' => $port->publicPort(),
                'router' => $port->routerPort(),
            ], $ports),
            'templates' => array_map(static fn (Template $template): array => [
                'name' => $template->name(),
                'drupal_env' => $template->drupalEnv(),
                'post_deploy' => [
                    'exec' => $template->postDeploy(),
                    'tty1' => $template->tty1(),
                    'tty2' => $template->tty2(),
                ],
            ], $this->templates->forProject($project)),
            'webhooks' => $this->webhookSection($project),
            'schedules' => $this->scheduleSection($project),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function webhookSection(Project $project): array
    {
        $sources = WebhookSource::cases();
        usort($sources, static fn (WebhookSource $a, WebhookSource $b): int => strcmp($a->value, $b->value));

        $section = [];
        foreach ($sources as $source) {
            $endpoint = $this->webhooks->endpoint($project->name(), $source);
            if (null === $endpoint) {
                continue;
            }
            $rules = $endpoint->rules();
            usort($rules, static fn (WebhookRule $a, WebhookRule $b): int => $a->position() <=> $b->position());
            $section[] = [
                'source' => $source->value,
                'rules' => array_map(static fn (WebhookRule $rule): array => [
                    'action' => $rule->action(),
                    'match' => self::sorted($rule->match()),
                    'params' => self::sorted($rule->params()),
                ], $rules),
            ];
        }

        return $section;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleSection(Project $project): array
    {
        $prefix = $project->name().'--';
        $own = array_values(array_filter(
            $this->schedules->all(),
            static fn (Schedule $schedule): bool => null !== $schedule->instanceId() && str_starts_with($schedule->instanceId(), $prefix),
        ));
        usort($own, static fn (Schedule $a, Schedule $b): int => strcmp($a->name(), $b->name()));

        return array_map(static fn (Schedule $schedule): array => [
            'name' => $schedule->name(),
            'cron' => $schedule->cron(),
            'timezone' => $schedule->timezone(),
            'action' => $schedule->action(),
            'instance' => substr((string) $schedule->instanceId(), \strlen($prefix)),
            'enabled' => $schedule->isEnabled(),
        ], $own);
    }

    /**
     * @return array<string, string>
     */
    private function secretValues(Project $project): array
    {
        $values = [];
        foreach ($this->secrets->listManaged($project->name()) as $summary) {
            $value = $this->secrets->getManaged($project->name(), $summary->name);
            if (null !== $value) {
                $values[$summary->name] = $value;
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
