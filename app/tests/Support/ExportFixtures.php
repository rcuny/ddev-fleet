<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Project\GitBotMode;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Webhooks\WebhookService;

/**
 * For KernelTestCase subclasses that use FleetDatabase: a project "blog" with one of every kind of thing an export
 * carries, and a fingerprint of the tables an import writes (to prove that a refused file changed nothing).
 */
trait ExportFixtures
{
    private const string SECRET_VALUE = 'zx-secret-value-0451';

    private function service(string $class): object
    {
        return self::getContainer()->get($class);
    }

    private function seedBlog(): void
    {
        $ports = $this->service(NamedPortService::class);
        \assert($ports instanceof NamedPortService);
        $ports->create(new NamedPortInput('typesense', 9108, 8108));

        $projects = $this->service(ProjectService::class);
        \assert($projects instanceof ProjectService);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', 'develop'));

        $templates = $this->service(TemplateService::class);
        \assert($templates instanceof TemplateService);
        $templates->create('blog', new TemplateInput('jira-work', 'dev', ['ddev composer install', 'ddev drush cr'], ['ddev logs'], []));
        $templates->create('blog', new TemplateInput('plain'));

        $projects->update('blog', new ProjectInput(
            'blog',
            'https://git.example.com/acme/blog.git',
            'develop',
            'jira-work',
            ['alt', 'preview'],
            ['typesense'],
            GitBotMode::Override,
            'Fleet Bot',
            'bot@example.com',
            '[A-Z]+-\d+',
            'themes/custom',
            true,
        ));

        $webhooks = $this->service(WebhookService::class);
        \assert($webhooks instanceof WebhookService);
        $webhooks->issueSecret('blog', WebhookSource::Jira, false);
        $webhooks->addRule('blog', WebhookSource::Jira, 'deploy', ['on_status' => 'Dispatched'], ['template' => 'jira-work']);
        $webhooks->issueSecret('blog', WebhookSource::Bitbucket, false);
        $webhooks->addRule('blog', WebhookSource::Bitbucket, 'deploy', ['on_event' => 'repo:push', 'repo' => 'acme/blog', 'branch' => 'feature/*'], ['template' => 'plain']);

        $schedules = $this->service(ScheduleService::class);
        \assert($schedules instanceof ScheduleService);
        $schedules->create(new ScheduleInput('blog-nightly', '0 3 * * *', 'Europe/Paris', 'redeploy', 'blog--main'), 'admin', false);
        $schedules->create(new ScheduleInput('blog-weekly', '0 4 * * 1', 'UTC', 'redeploy', 'blog--stage'), 'admin', false);
        $schedules->pause('blog-weekly', 'admin');

        $secrets = $this->service(SecretService::class);
        \assert($secrets instanceof SecretService);
        $secrets->put('blog', 'JIRA_TOKEN', self::SECRET_VALUE, 'admin');
    }

    /**
     * Row counts of every table an import writes.
     *
     * @return array<string, int>
     */
    private function fingerprint(): array
    {
        $connection = $this->entityManager()->getConnection();
        $counts = [];
        foreach (['project', 'template', 'named_port', 'project_port', 'webhook_endpoint', 'webhook_rule', 'schedule', 'secret', 'audit_entry'] as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }
}
