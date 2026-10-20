<?php

declare(strict_types=1);

namespace App\Fleet\Export;

use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Fleet\Secrets\SecretName;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Secrets\SecretValue;
use App\Fleet\Webhooks\WebhookSecrets;
use App\Fleet\Webhooks\WebhookService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports a project export file (see ExportFormat): preview() says what would happen, apply() does it.
 *
 * Every value goes through the same rules as the pages and the `fleet project|template|port|schedule|secret`
 * commands (the services' validate() methods), and the whole file is checked before anything is written. apply()
 * writes in one database transaction, so a refused file or a failure leaves the database as it was.
 *
 * Matching is by project name. Things the file does not mention are left alone (a template, a schedule or a secret
 * that exists only on this host stays). Webhook endpoints get a new secret of their own on this host.
 */
final readonly class ProjectImporter
{
    public function __construct(
        private ExportParser $parser,
        private PassphraseSealer $sealer,
        private ProjectExporter $exporter,
        private ProjectRepository $projects,
        private ProjectService $projectService,
        private TemplateService $templates,
        private NamedPortRepository $ports,
        private NamedPortService $portService,
        private WebhookRepository $webhooks,
        private WebhookService $webhookService,
        private WebhookSecrets $webhookSecrets,
        private ScheduleRepository $schedules,
        private ScheduleService $scheduleService,
        private SecretStore $secretStore,
        private SecretService $secretService,
        private AuditLog $audit,
        private EntityManagerInterface $em,
        private ProjectAccessRefresher $access,
        private InstanceSiteRefresher $sites,
    ) {
    }

    /**
     * Whether the file needs a passphrase to import its secrets; false for a file that has none.
     */
    public function hasSealedSecrets(string $text): bool
    {
        try {
            return null !== $this->parser->parse($text)['secrets'];
        } catch (InvalidExport) {
            return false;
        }
    }

    /**
     * What applying the file would do. Never writes. A file that cannot be read, or fails a rule, gives a plan with
     * errors and no items.
     */
    public function preview(string $text, #[\SensitiveParameter] ?string $passphrase = null): ImportPlan
    {
        try {
            $document = $this->parser->parse($text);
        } catch (InvalidExport $e) {
            return new ImportPlan('', [], $e->problems);
        }

        return $this->analyse($document, $passphrase)[0];
    }

    /**
     * Applies the file in one transaction and returns the plan it carried out (its warnings say what could not be
     * refreshed afterwards).
     *
     * @throws InvalidExport when the file is refused or the write failed: nothing was written
     */
    public function apply(string $text, #[\SensitiveParameter] ?string $passphrase, string $by): ImportPlan
    {
        $document = $this->parser->parse($text);
        [$plan, $secrets] = $this->analyse($document, $passphrase);
        if ($plan->hasErrors()) {
            throw new InvalidExport($plan->errors);
        }
        if (!$plan->changes()) {
            return $plan;
        }

        $name = $document['project']['name'];
        $existed = null !== $this->projects->findByName($name);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->write($document, $plan, $secrets, $by);
            $this->em->flush();
            $connection->commit();
        } catch (ValidationFailed|Refused|NotFound $e) {
            $this->abandon();

            throw InvalidExport::of('The import failed and nothing was written: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->abandon();

            throw $e;
        }

        $this->audit->record($by, AuditLog::PROJECT_IMPORT, $name);

        return new ImportPlan($plan->project, $plan->items, [], [...$plan->warnings, ...$this->refresh($name, $existed, $plan)]);
    }

    private function abandon(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->em->clear();
    }

    /**
     * The things that follow a committed change: the sign-in rules know the new project, and the Caddy sites of its
     * instances follow a changed set of ports. A failure here is a warning: the data is saved.
     *
     * @return list<string>
     */
    private function refresh(string $name, bool $existed, ImportPlan $plan): array
    {
        $warning = null;
        if (!$existed) {
            $this->access->refresh();
            $warning = $this->access->takeWarning();
        } elseif (ImportItem::UPDATE === $this->actionOf($plan, 'project', $name) && $this->portsChanged($plan, $name)) {
            $project = $this->projects->findByName($name);
            if (null !== $project) {
                $this->sites->refresh($project);
                $warning = $this->sites->takeWarning();
            }
        }

        return null === $warning ? [] : [$warning];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{ImportPlan, array<string, string>|null} the plan and the opened secrets (null: none to import)
     */
    private function analyse(array $document, #[\SensitiveParameter] ?string $passphrase): array
    {
        $project = $document['project'];
        $slug = $project['name'];
        $existing = $this->projects->findByName($slug);
        /** @var array<string, mixed>|null $current */
        $current = null === $existing ? null : $this->exporter->document($existing);
        $items = [];
        $errors = [];
        $warnings = [];

        $templateNames = array_column($document['templates'], 'name');
        $knownTemplates = array_values(array_unique([...$templateNames, ...(null === $existing ? [] : $this->projects->templateNames($existing))]));

        $this->planPorts($document, $items, $errors);
        $this->planProject($document, $existing, $current, $knownTemplates, $items, $errors);
        $this->planTemplates($document, $current, $knownTemplates, $items, $errors);
        $this->planWebhooks($document, $current, $knownTemplates, $items, $errors);
        $this->planSchedules($document, $existing, $current, $items, $errors);
        $secrets = $this->planSecrets($document, $slug, $passphrase, $items, $errors, $warnings);

        return [new ImportPlan($slug, $items, $errors, $warnings), $secrets];
    }

    /**
     * @param array<string, mixed> $document
     * @param list<ImportItem>     $items
     * @param list<string>         $errors
     */
    private function planPorts(array $document, array &$items, array &$errors): void
    {
        $pending = [];
        foreach ($document['ports'] as $i => $port) {
            $input = new NamedPortInput($port['name'], $port['public'], $port['router']);
            $numbers = \sprintf('public %d, router %d', $port['public'], $port['router']);
            $found = $this->ports->findByName($port['name']);
            if (null === $found) {
                $problems = $this->portService->validate($input, $pending);
                foreach ($problems as $field => $message) {
                    $errors[] = "ports[$i].$field: $message";
                }
                $pending[] = $input->normalised();
                $items[] = new ImportItem('port', $port['name'], [] === $problems ? ImportItem::CREATE : ImportItem::SKIPPED, $numbers);
            } elseif ($found->publicPort() === $port['public'] && $found->routerPort() === $port['router']) {
                $items[] = new ImportItem('port', $port['name'], ImportItem::UNCHANGED, $numbers);
            } else {
                $errors[] = \sprintf('ports[%d]: this host already has a port with this name and other numbers (public %d, router %d); a catalogue port cannot be changed by an import.', $i, $found->publicPort(), $found->routerPort());
                $items[] = new ImportItem('port', $port['name'], ImportItem::SKIPPED, $numbers);
            }
        }
    }

    /**
     * @param array<string, mixed>      $document
     * @param array<string, mixed>|null $current
     * @param list<string>              $knownTemplates
     * @param list<ImportItem>          $items
     * @param list<string>              $errors
     */
    private function planProject(array $document, ?Project $existing, ?array $current, array $knownTemplates, array &$items, array &$errors): void
    {
        $project = $document['project'];
        foreach ($this->projectService->validate($this->projectInput($project, null, []), $existing) as $field => $message) {
            $errors[] = 'project.'.self::snake($field).': '.$message;
        }
        if (null !== $project['default_template'] && !\in_array($project['default_template'], $knownTemplates, true)) {
            $errors[] = 'project.default_template: this project has no template with this name.';
        }

        $items[] = null === $current
            ? new ImportItem('project', $project['name'], ImportItem::CREATE)
            : self::compare('project', $project['name'], $current['project'], $project);
    }

    /**
     * @param array<string, mixed>      $document
     * @param array<string, mixed>|null $current
     * @param list<string>              $knownTemplates
     * @param list<ImportItem>          $items
     * @param list<string>              $errors
     */
    private function planTemplates(array $document, ?array $current, array $knownTemplates, array &$items, array &$errors): void
    {
        if (\count($knownTemplates) > TemplateService::MAX_PER_PROJECT) {
            $errors[] = \sprintf('templates: a project has at most %d templates.', TemplateService::MAX_PER_PROJECT);
        }
        $before = self::byName($current['templates'] ?? []);
        foreach ($document['templates'] as $i => $template) {
            foreach ($this->templates->validate(self::templateInput($template)) as $field => $message) {
                $errors[] = "templates[$i].".self::snake($field).': '.$message;
            }
            $items[] = isset($before[$template['name']])
                ? self::compare('template', $template['name'], $before[$template['name']], $template)
                : new ImportItem('template', $template['name'], ImportItem::CREATE);
        }
    }

    /**
     * @param array<string, mixed>      $document
     * @param array<string, mixed>|null $current
     * @param list<string>              $knownTemplates
     * @param list<ImportItem>          $items
     * @param list<string>              $errors
     */
    private function planWebhooks(array $document, ?array $current, array $knownTemplates, array &$items, array &$errors): void
    {
        $before = [];
        foreach ($current['webhooks'] ?? [] as $endpoint) {
            $before[$endpoint['source']] = $endpoint;
        }
        foreach ($document['webhooks'] as $i => $endpoint) {
            $source = WebhookSource::from($endpoint['source']);
            $siblings = [];
            foreach ($endpoint['rules'] as $j => $rule) {
                foreach ($this->webhookService->ruleErrors($source, $rule['action'], $rule['match'], $rule['params'], $knownTemplates, $siblings) as $field => $message) {
                    $errors[] = "webhooks[$i].rules[$j].".self::snake($field).': '.$message;
                }
                $siblings[] = $rule['match'];
            }
            $name = $source->label().' webhook';
            if (!isset($before[$endpoint['source']])) {
                $items[] = new ImportItem('webhook', $name, ImportItem::CREATE, 'a new secret is issued on this host; rotate it on the project page to read it');
            } elseif ($before[$endpoint['source']]['rules'] === $endpoint['rules']) {
                $items[] = new ImportItem('webhook', $name, ImportItem::UNCHANGED);
            } else {
                $items[] = new ImportItem('webhook', $name, ImportItem::UPDATE, 'rules replaced');
            }
        }
    }

    /**
     * @param array<string, mixed>      $document
     * @param array<string, mixed>|null $current
     * @param list<ImportItem>          $items
     * @param list<string>              $errors
     */
    private function planSchedules(array $document, ?Project $existing, ?array $current, array &$items, array &$errors): void
    {
        $slug = $document['project']['name'];
        $before = self::byName($current['schedules'] ?? []);
        foreach ($document['schedules'] as $i => $schedule) {
            $id = null === $schedule['instance'] ? null : $slug.'--'.$schedule['instance'];
            if (null !== $id && !InstanceNaming::isInstanceId($id)) {
                $errors[] = "schedules[$i].instance: not a valid instance label.";
                $items[] = new ImportItem('schedule', $schedule['name'], ImportItem::SKIPPED);
                continue;
            }
            $stored = $this->schedules->findByName($schedule['name']);
            if (null !== $stored && !isset($before[$schedule['name']])) {
                $errors[] = "schedules[$i].name: a schedule with this name exists on this host and does not belong to this project.";
                $items[] = new ImportItem('schedule', $schedule['name'], ImportItem::SKIPPED);
                continue;
            }
            // The instance may not exist on this host yet (it is deployed after the import): only a missing target is refused.
            $input = new ScheduleInput($schedule['name'], $schedule['cron'], $schedule['timezone'], $schedule['action'], $id);
            foreach ($this->scheduleService->validate($input, null === $stored, null === $id) as $field => $message) {
                $errors[] = "schedules[$i].".('instance' === $field ? 'instance' : $field).': '.$message;
            }
            $items[] = isset($before[$schedule['name']])
                ? self::compare('schedule', $schedule['name'], $before[$schedule['name']], $schedule)
                : new ImportItem('schedule', $schedule['name'], ImportItem::CREATE);
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param list<ImportItem>     $items
     * @param list<string>         $errors
     * @param list<string>         $warnings
     *
     * @return array<string, string>|null
     */
    private function planSecrets(array $document, string $slug, #[\SensitiveParameter] ?string $passphrase, array &$items, array &$errors, array &$warnings): ?array
    {
        $sealed = $document['secrets'];
        if (null === $sealed) {
            return null;
        }
        if (null === $passphrase || '' === $passphrase) {
            $items[] = new ImportItem('secret', 'sealed secrets', ImportItem::SKIPPED, 'no passphrase given: the secrets are not imported');
            $warnings[] = 'The file holds sealed secrets and no passphrase was given: they were not imported.';

            return null;
        }

        try {
            $payload = $this->sealer->open($sealed, $passphrase);
        } catch (InvalidExport $e) {
            array_push($errors, ...$e->problems);

            return null;
        }
        $values = $payload['secrets'] ?? null;
        if (($payload['project'] ?? null) !== $slug || !\is_array($values) || \count($values) > 500) {
            $errors[] = 'secrets: the sealed block does not belong to this project file.';

            return null;
        }

        $clean = [];
        ksort($values);
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (!\is_string($value) || null !== SecretName::error($name) || null !== SecretValue::error(SecretValue::normalise($value))) {
                $errors[] = 'secrets: a sealed secret has an invalid name or value ('.(null === SecretName::error($name) ? $name : '(invalid name)').').';
                continue;
            }
            $value = SecretValue::normalise($value);
            $clean[$name] = $value;
            $stored = $this->secretStore->getManaged($slug, $name);
            $items[] = new ImportItem('secret', $name, null === $stored ? ImportItem::CREATE : (hash_equals($stored, $value) ? ImportItem::UNCHANGED : ImportItem::UPDATE));
        }

        return $clean;
    }

    /**
     * @param array<string, mixed>       $document
     * @param array<string, string>|null $secrets
     */
    private function write(array $document, ImportPlan $plan, ?array $secrets, string $by): void
    {
        $slug = $document['project']['name'];
        $project = $document['project'];

        foreach ($document['ports'] as $port) {
            if (ImportItem::CREATE === $this->actionOf($plan, 'port', $port['name'])) {
                $this->portService->create(new NamedPortInput($port['name'], $port['public'], $port['router']));
            }
        }

        $existing = $this->projects->findByName($slug);
        $keptDefault = $existing?->defaultTemplate();
        $input = $this->projectInput($project, $keptDefault, $project['ports']);
        if (null === $existing) {
            $this->projectService->create($input, false);
        } elseif (ImportItem::UPDATE === $this->actionOf($plan, 'project', $slug)) {
            $this->projectService->update($slug, $input, false);
        }

        foreach ($document['templates'] as $template) {
            match ($this->actionOf($plan, 'template', $template['name'])) {
                ImportItem::CREATE => $this->templates->create($slug, self::templateInput($template)),
                ImportItem::UPDATE => $this->templates->update($slug, $template['name'], self::templateInput($template)),
                default => null,
            };
        }

        if ($project['default_template'] !== $keptDefault) {
            $this->projectService->update($slug, $input->with(['defaultTemplate' => $project['default_template']]), false);
        }

        foreach ($document['webhooks'] as $endpoint) {
            $this->writeWebhook($slug, $endpoint, $plan);
        }
        foreach ($document['schedules'] as $schedule) {
            $this->writeSchedule($slug, $schedule, $plan, $by);
        }
        foreach ($secrets ?? [] as $name => $value) {
            if (ImportItem::UNCHANGED !== $this->actionOf($plan, 'secret', (string) $name)) {
                $this->secretService->put($slug, $name, $value, $by);
            }
        }
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    private function writeWebhook(string $slug, array $endpoint, ImportPlan $plan): void
    {
        $source = WebhookSource::from($endpoint['source']);
        $action = $this->actionOf($plan, 'webhook', $source->label().' webhook');
        if (ImportItem::CREATE !== $action && ImportItem::UPDATE !== $action) {
            return;
        }

        $stored = $this->webhooks->endpoint($slug, $source);
        if (null === $stored) {
            $project = $this->projects->findByName($slug) ?? throw NotFound::project($slug);
            // The secret is for the operator to read later (rotate); the file never carries one.
            $this->webhookSecrets->issue($project, $source);
        } else {
            foreach ($stored->rules() as $rule) {
                $this->webhooks->removeRule($rule);
            }
            // The loaded rule collection is stale now: addRule() must see the endpoint as stored.
            $this->em->clear();
        }
        foreach ($endpoint['rules'] as $rule) {
            $this->webhookService->addRule($slug, $source, $rule['action'], $rule['match'], $rule['params']);
        }
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function writeSchedule(string $slug, array $schedule, ImportPlan $plan, string $by): void
    {
        $action = $this->actionOf($plan, 'schedule', $schedule['name']);
        if (ImportItem::CREATE !== $action && ImportItem::UPDATE !== $action) {
            return;
        }
        $id = null === $schedule['instance'] ? null : $slug.'--'.$schedule['instance'];
        $input = new ScheduleInput($schedule['name'], $schedule['cron'], $schedule['timezone'], $schedule['action'], $id);

        if (ImportItem::CREATE === $action) {
            $this->scheduleService->create($input, $by, false);
        } else {
            $this->scheduleService->update($schedule['name'], $input, $by, false);
        }
        if ($schedule['enabled']) {
            $this->scheduleService->resume($schedule['name'], $by);
        } else {
            $this->scheduleService->pause($schedule['name'], $by);
        }
    }

    /**
     * @param array<string, mixed> $project
     * @param list<string>         $ports
     */
    private function projectInput(array $project, ?string $defaultTemplate, array $ports): ProjectInput
    {
        return new ProjectInput(
            $project['name'],
            $project['git'],
            $project['default_branch'],
            $defaultTemplate,
            $project['additional_hostnames'],
            $ports,
            GitBotMode::from($project['git_bot']['mode']),
            $project['git_bot']['name'],
            $project['git_bot']['email'],
            $project['issue_id_regexp'],
            $project['display_submodule_branch'],
            $project['typesense'],
        );
    }

    /**
     * @param array<string, mixed> $template
     */
    private static function templateInput(array $template): TemplateInput
    {
        return new TemplateInput(
            $template['name'],
            $template['drupal_env'],
            $template['post_deploy']['exec'],
            $template['post_deploy']['tty1'],
            $template['post_deploy']['tty2'],
        );
    }

    private function actionOf(ImportPlan $plan, string $kind, string $name): ?string
    {
        foreach ($plan->items as $item) {
            if ($item->kind === $kind && $item->name === $name) {
                return $item->action;
            }
        }

        return null;
    }

    private function portsChanged(ImportPlan $plan, string $project): bool
    {
        foreach ($plan->items as $item) {
            if ('project' === $item->kind && $item->name === $project) {
                return 1 === preg_match('/\bports\b/', (string) $item->note);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function compare(string $kind, string $name, array $before, array $after): ImportItem
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = (string) $key;
            }
        }

        return [] === $changed
            ? new ImportItem($kind, $name, ImportItem::UNCHANGED)
            : new ImportItem($kind, $name, ImportItem::UPDATE, 'changed: '.implode(', ', $changed));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private static function byName(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        return $byName;
    }

    private static function snake(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }
}
