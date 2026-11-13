<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Project\ProjectLink;
use App\Domain\Project\ProjectLinkRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\Rule\TextBlock;

/**
 * FLE-299: the one path to declare, list and delete links between projects (pages, commands, import). The target service
 * is not checked here (the checkout may not exist yet): the isolation pass reports a link whose service is missing as pending.
 */
final readonly class ProjectLinkService
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectLinkRepository $links,
        private AuditLog $audit,
        private ?\App\Fleet\Isolation\IsolationConverger $network = null,
    ) {
    }

    /**
     * @return list<ProjectLink>
     */
    public function list(?string $project = null): array
    {
        if (null === $project) {
            return $this->links->all();
        }
        $found = $this->projects->findByName($project) ?? throw NotFound::project($project);

        return array_merge($this->links->outgoing($found), $this->links->incoming($found));
    }

    /**
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function create(string $source, string $target, string $service, int $port, string $label, string $note, string $actor): ProjectLink
    {
        $errors = [];
        $sourceProject = $this->projects->findByName($source);
        $targetProject = $this->projects->findByName($target);
        if (null === $sourceProject) {
            $errors['source'] = 'There is no project with this name.';
        }
        if (null === $targetProject) {
            $errors['target'] = 'There is no project with this name.';
        }
        if (null !== $sourceProject && $source === $target) {
            $errors['target'] = 'A project does not need a link to itself.';
        }
        $service = '' === $service ? 'web' : $service;
        if (null !== $e = ResourceName::error($service, false)) {
            $errors['service'] = $e;
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Enter a TCP port between 1 and 65535.';
        }
        $label = '' === $label ? ProjectLink::SAME_LABEL : $label;
        if (ProjectLink::SAME_LABEL !== $label && null !== $e = ResourceName::error($label, false)) {
            $errors['label'] = $e;
        }
        if (null !== $e = TextBlock::error($note, 500)) {
            $errors['note'] = $e;
        }
        if (null === $sourceProject || null === $targetProject || [] !== $errors) {
            throw new ValidationFailed($errors);
        }
        if (null !== $this->links->find($sourceProject, $targetProject, $service, $port, $label)) {
            throw new ValidationFailed(['port' => 'This link already exists.']);
        }

        $link = new ProjectLink($sourceProject, $targetProject, $service, $port, $label, trim($note), $actor);
        $this->links->save($link);
        $this->audit->record($actor, AuditLog::PROJECT_LINK_CREATE, self::describe($link));
        $this->network?->pass();

        return $link;
    }

    /**
     * @throws NotFound
     */
    public function delete(string $source, string $target, string $service, int $port, string $label, string $actor): void
    {
        $sourceProject = $this->projects->findByName($source) ?? throw NotFound::project($source);
        $targetProject = $this->projects->findByName($target) ?? throw NotFound::project($target);
        $link = $this->links->find($sourceProject, $targetProject, '' === $service ? 'web' : $service, $port, '' === $label ? ProjectLink::SAME_LABEL : $label)
            ?? throw new Refused('There is no such link.');
        $this->links->delete($link);
        $this->audit->record($actor, AuditLog::PROJECT_LINK_DELETE, self::describe($link));
        // A deleted link closes at once, not at the next watcher pass (pass() never throws).
        $this->network?->pass();
    }

    public static function describe(ProjectLink $link): string
    {
        return \sprintf('%s -> %s/%s:%d (%s)', $link->source()->name(), $link->target()->name(), $link->service(), $link->port(), $link->targetLabel());
    }
}
