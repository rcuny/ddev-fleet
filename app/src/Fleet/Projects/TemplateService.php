<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\SshIdentities;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\Rule\Capacity;
use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\Rule\DrupalEnv;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\Rule\SshKeyNames;
use App\Fleet\Ssh\ExtraSshKey;
use App\Fleet\System\SlicesApplier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The one path to create, change and delete the templates of a project. Templates are always read through the
 * repository: the collection a Project loaded earlier goes stale when a template is saved.
 */
final readonly class TemplateService
{
    public const int MAX_PER_PROJECT = 50;

    public function __construct(
        private ProjectRepository $projects,
        private TemplateRepository $templates,
        private ExtraSshKey $sshKeys,
        private ?SlicesApplier $slices = null,
        private ?\App\Fleet\Ssh\PushKeyCheck $pushCheck = null,
    ) {
    }

    /**
     * FLE-271: for a project with container_ssh on, a warning when its push key is not registered on the remote's git host
     * (null when all is well, the check is off, or the host cannot be tested). Never blocks a save.
     */
    public function pushKeyWarning(string $project): ?string
    {
        $owner = $this->project($project);
        if (null === $this->pushCheck || !$owner->containerSsh()) {
            return null;
        }
        try {
            $host = \App\Fleet\Ssh\PushKeyCheck::hostFromRemote($owner->git());
            $state = $this->pushCheck->run($host, true);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return \App\Fleet\Ssh\PushKeyState::NotRegistered === $state || \App\Fleet\Ssh\PushKeyState::HostNotTrusted === $state ? $state->message($host) : null;
    }

    /**
     * @return list<Template> ordered by name
     *
     * @throws NotFound
     */
    public function list(string $project): array
    {
        return $this->templates->forProject($this->project($project));
    }

    /**
     * @throws NotFound
     */
    public function get(string $project, string $name): Template
    {
        return $this->templates->find($this->project($project), $name) ?? throw NotFound::template($project, $name);
    }

    /**
     * @throws NotFound         when the project does not exist
     * @throws ValidationFailed
     */
    public function create(string $project, TemplateInput $input): Template
    {
        $owner = $this->project($project);
        $input = $input->normalised();
        $errors = $this->check($input, $owner->containerSsh(), $owner);

        if (!isset($errors['name'])) {
            if (null !== $this->templates->find($owner, $input->name)) {
                $errors['name'] = 'A template with this name already exists in this project.';
            } elseif ($this->templates->count($owner) >= self::MAX_PER_PROJECT) {
                $errors['name'] = \sprintf('A project has at most %d templates.', self::MAX_PER_PROJECT);
            }
        }
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $now = new \DateTimeImmutable();
        $template = new Template($owner, $input->name, $now);
        $template->changeDrupalEnv($input->drupalEnv, $now);
        $template->changeCommands($input->postDeploy, $input->tty1, $input->tty2, $now);
        $template->changeSshKeys($input->sshKeys, $now);
        $template->changeSshIdentities($input->sshIdentities, $now);
        $template->changeCapacity($input->cpuThreads, $input->slots, $now);

        try {
            $this->templates->save($template);
        } catch (UniqueConstraintViolationException) {
            // Two creates raced past the existence check. The entity manager is closed now: touch nothing else.
            throw new ValidationFailed(['name' => 'A template with this name already exists in this project.']);
        }
        // The template's slice must exist before its first instance starts. A failure is a warning.
        $this->slices?->sync();

        return $template;
    }

    /**
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function update(string $project, string $name, TemplateInput $input): Template
    {
        $template = $this->get($project, $name);
        $input = $input->normalised();
        $errors = $this->check($input, $template->project()->containerSsh(), $template->project());
        if ($input->name !== $template->name()) {
            $errors = ['name' => 'The name cannot be changed.'] + $errors;
        }
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $now = new \DateTimeImmutable();
        $limitBefore = $template->cpuThreads();
        $template->changeDrupalEnv($input->drupalEnv, $now);
        $template->changeCommands($input->postDeploy, $input->tty1, $input->tty2, $now);
        $template->changeSshKeys($input->sshKeys, $now);
        $template->changeSshIdentities($input->sshIdentities, $now);
        $template->changeCapacity($input->cpuThreads, $input->slots, $now);
        $this->templates->save($template);
        // A new limit reaches the running slice at once (no restart of the instances).
        if ($limitBefore !== $template->cpuThreads()) {
            $this->slices?->sync();
        }

        return $template;
    }

    /**
     * @throws NotFound
     * @throws Refused  when it is the project's default template
     */
    public function delete(string $project, string $name): void
    {
        $template = $this->get($project, $name);
        if ($template->project()->defaultTemplate() === $template->name()) {
            throw new Refused('This is the default template of the project: choose another default template first.');
        }

        $this->templates->remove($template);
        $this->slices?->sync();
    }

    /**
     * The field => message map create() would refuse the input with (the name's uniqueness and the project's limit
     * aside), without touching the database. A project import checks a whole file with it before it writes anything.
     *
     * @param bool|null    $containerSsh whether the project (as it will be after the import) has container_ssh on; null skips that check
     * @param Project|null $project      the project whose limits the capacity values are checked against; null skips that check
     *
     * @return array<string, string>
     */
    public function validate(TemplateInput $input, ?bool $containerSsh = null, ?Project $project = null): array
    {
        return $this->check($input->normalised(), $containerSsh, $project);
    }

    /**
     * @return array<string, string> the first error of every field except the name rules that need the database
     */
    private function check(TemplateInput $input, ?bool $containerSsh, ?Project $project = null): array
    {
        $errors = [];
        if (null !== $error = ResourceName::error($input->name)) {
            $errors['name'] = $error;
        }
        if (null !== $input->drupalEnv && null !== $error = DrupalEnv::error($input->drupalEnv)) {
            $errors['drupalEnv'] = $error;
        }
        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            if (null !== $error = CommandLines::error($input->{$field})) {
                $errors[$field] = $error;
            }
        }

        if (null !== $error = $this->sshKeysError($input->sshKeys, $containerSsh)) {
            $errors['sshKeys'] = $error;
        }

        if (SshIdentities::ExtrasOnly === $input->sshIdentities && [] === $input->sshKeys) {
            $errors['sshIdentities'] = '"Extra keys only" needs at least one extra SSH key: otherwise the instance would have no key at all.';
        }

        foreach (['cpuThreads' => ['CPU threads', $project?->cpuThreads()], 'slots' => ['Slots', $project?->slots()]] as $field => [$what, $limit]) {
            $error = Capacity::error($input->{$field}) ?? Capacity::templateAboveProject($what, $input->{$field}, $limit);
            if (null !== $error) {
                $errors[$field] = $error;
            }
        }

        return $errors;
    }

    /**
     * FLE-190: extra keys only make sense for a project that gets an agent (container_ssh), and each key file must exist on
     * this host with the right mode and owner.
     *
     * @param list<string> $names
     */
    private function sshKeysError(array $names, ?bool $containerSsh): ?string
    {
        if ([] === $names) {
            return null;
        }
        if (null !== $error = SshKeyNames::error($names)) {
            return $error;
        }
        if (false === $containerSsh) {
            return 'Extra SSH keys need "git over SSH in the container" (container_ssh) turned on for the project first.';
        }
        foreach ($names as $name) {
            if (null !== $error = $this->sshKeys->problem($name)) {
                return $error;
            }
        }

        return null;
    }

    private function project(string $name): Project
    {
        return $this->projects->findByName($name) ?? throw NotFound::project($name);
    }
}
