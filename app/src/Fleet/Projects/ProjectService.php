<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectInstanceGuard;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\Rule\GitIdentity;
use App\Fleet\Projects\Rule\GitUrl;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\Rule\SubmodulePath;
use App\Fleet\Secrets\SecretService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The one path to create, change and delete projects, for the pages and the commands alike. Every call normalises the
 * input, collects the first error of every field and throws one ValidationFailed before anything is written.
 */
final readonly class ProjectService
{
    public const int MAX_HOSTNAMES = 10;
    public const int MAX_PORTS = 50;
    /** Instance ids named in a refusal message. */
    private const int LISTED_INSTANCES = 10;

    public function __construct(
        private ProjectRepository $projects,
        private NamedPortRepository $ports,
        private ProjectInstanceGuard $instances,
        private ?ProjectAccessRefresher $accessRefresher = null,
        private ?SecretService $secrets = null,
        private ?InstanceSiteRefresher $sites = null,
    ) {
    }

    /**
     * @return list<Project> ordered by name
     */
    public function list(): array
    {
        return $this->projects->all();
    }

    /**
     * @throws NotFound
     */
    public function get(string $name): Project
    {
        return $this->projects->findByName($name) ?? throw NotFound::project($name);
    }

    /**
     * @throws ValidationFailed
     */
    public function create(ProjectInput $input, bool $refresh = true): Project
    {
        $input = $input->normalised();
        [$errors, $ports] = $this->check($input, null);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $now = new \DateTimeImmutable();
        $project = new Project($input->name, $input->git, $now);
        $this->apply($project, $input, $ports, $now);

        try {
            $this->projects->save($project);
        } catch (UniqueConstraintViolationException) {
            // Two creates raced past the existence check. The entity manager is closed now: touch nothing else.
            throw new ValidationFailed(['name' => 'A project with this name already exists.']);
        }
        // Every project has its own Authelia access rule: a failure to install it is a warning (takeWarning()).
        if ($refresh) {
            $this->accessRefresher?->refresh();
        }

        return $project;
    }

    /**
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function update(string $name, ProjectInput $input, bool $refresh = true): Project
    {
        $project = $this->get($name);
        $input = $input->normalised();
        [$errors, $ports] = $this->check($input, $project);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $before = self::portNames($project->ports());
        $this->apply($project, $input, $ports, new \DateTimeImmutable());
        $this->projects->save($project);
        // Each port is a site block of every instance: the sites follow an assignment (a failure is a warning,
        // takeWarning() on the refresher). A named port that is created or deleted is assigned to no project.
        if ($refresh && $before !== self::portNames($project->ports())) {
            $this->sites?->refresh($project);
        }

        return $project;
    }

    /**
     * Deletes the project with its templates, port assignments and secrets ($by is recorded for each secret removed).
     *
     * @throws NotFound
     * @throws Refused  when the project still has instances
     */
    public function delete(string $name, string $by): void
    {
        $project = $this->get($name);
        $ids = $this->instances->instanceIds($project);
        if ([] !== $ids) {
            $listed = \array_slice($ids, 0, self::LISTED_INSTANCES);
            $more = \count($ids) - \count($listed);

            throw new Refused(\sprintf('%d instance(s) exist: destroy them first (%s%s).', \count($ids), implode(', ', $listed), $more > 0 ? \sprintf(' and %d more', $more) : ''));
        }

        $this->secrets?->removeAllOfProject($name, $by);
        $this->projects->remove($project);
        $this->accessRefresher?->refresh();
    }

    /**
     * @param list<NamedPort> $ports
     *
     * @return list<string> sorted
     */
    private static function portNames(array $ports): array
    {
        $names = array_map(static fn (NamedPort $port): string => $port->name(), $ports);
        sort($names);

        return $names;
    }

    /**
     * The field => message map create() (no $existing) or update() would refuse the input with, without touching the
     * database. A project import checks a whole file with it before it writes anything.
     *
     * @return array<string, string>
     */
    public function validate(ProjectInput $input, ?Project $existing): array
    {
        return $this->check($input->normalised(), $existing)[0];
    }

    /**
     * @return array{array<string, string>, list<NamedPort>} the first error of every field, and the catalogue entries
     *                                                       the input names
     */
    private function check(ProjectInput $input, ?Project $existing): array
    {
        $errors = [];

        if (null !== $existing) {
            if ($input->name !== $existing->name()) {
                $errors['name'] = 'The name cannot be changed.';
            }
        } elseif (null !== $error = ResourceName::error($input->name)) {
            $errors['name'] = $error;
        } elseif (null !== $this->projects->findByName($input->name)) {
            $errors['name'] = 'A project with this name already exists.';
        }

        if (null !== $error = GitUrl::error($input->git)) {
            $errors['git'] = $error;
        }
        if (null !== $input->defaultBranch && null !== $error = BranchName::error($input->defaultBranch)) {
            $errors['defaultBranch'] = $error;
        }
        if (null !== $input->defaultTemplate) {
            if (null === $existing) {
                $errors['defaultTemplate'] = 'A default template can be chosen once the project has templates.';
            } elseif (!\in_array($input->defaultTemplate, $this->projects->templateNames($existing), true)) {
                $errors['defaultTemplate'] = 'This project has no template with this name.';
            }
        }
        if (null !== $error = $this->hostnamesError($input)) {
            $errors['additionalHostnames'] = $error;
        }

        $ports = [];
        if (\count($input->ports) > self::MAX_PORTS) {
            $errors['ports'] = \sprintf('At most %d ports can be assigned.', self::MAX_PORTS);
        } else {
            $ports = $this->ports->findManyByNames($input->ports);
            if (\count($ports) !== \count($input->ports)) {
                $errors['ports'] = $this->unknownPortsError($input->ports, $ports);
            }
        }

        if (GitBotMode::Override === $input->gitBotMode) {
            if (null === $input->gitBotName && null === $input->gitBotEmail) {
                $errors['gitBotName'] = 'Enter a name, an email or both, or choose another git identity mode.';
            }
            if (null !== $input->gitBotName && null !== $error = GitIdentity::nameError($input->gitBotName)) {
                $errors['gitBotName'] = $error;
            }
            if (null !== $input->gitBotEmail && null !== $error = GitIdentity::emailError($input->gitBotEmail)) {
                $errors['gitBotEmail'] = $error;
            }
        }
        if (null !== $input->issueIdRegexp && null !== $error = IssueIdPattern::error($input->issueIdRegexp)) {
            $errors['issueIdRegexp'] = $error;
        }
        if (null !== $input->displaySubmoduleBranch && null !== $error = SubmodulePath::error($input->displaySubmoduleBranch)) {
            $errors['displaySubmoduleBranch'] = $error;
        }

        return [$errors, $ports];
    }

    private function hostnamesError(ProjectInput $input): ?string
    {
        $hostnames = $input->additionalHostnames;
        if (\count($hostnames) > self::MAX_HOSTNAMES) {
            return \sprintf('At most %d additional hostnames are allowed.', self::MAX_HOSTNAMES);
        }
        foreach ($hostnames as $index => $hostname) {
            if (null !== $error = ResourceName::hostnameError($hostname, $input->name)) {
                return \sprintf('Hostname %d: %s', $index + 1, $error);
            }
        }
        if (\count(array_unique($hostnames)) !== \count($hostnames)) {
            return 'Each hostname can be listed once.';
        }

        return null;
    }

    /**
     * @param list<string>    $asked
     * @param list<NamedPort> $found
     */
    private function unknownPortsError(array $asked, array $found): string
    {
        $known = array_map(static fn (NamedPort $port): string => $port->name(), $found);
        $shown = [];
        foreach (array_diff($asked, $known) as $name) {
            // Only a well-formed name is echoed: anything else could be arbitrary user input.
            $shown[] = null === ResourceName::error($name, false) ? $name : '(invalid name)';
        }

        return \sprintf('Unknown port(s): %s.', implode(', ', \array_slice($shown, 0, 5)));
    }

    /**
     * @param list<NamedPort> $ports
     */
    private function apply(Project $project, ProjectInput $input, array $ports, \DateTimeImmutable $now): void
    {
        $project->changeGit($input->git, $now);
        $project->changeDefaults($input->defaultBranch, $input->defaultTemplate, $now);
        $project->changeHostnames($input->additionalHostnames, $now);
        $project->changePorts($ports, $now);
        $project->changeGitBot($input->gitBotMode, $input->gitBotName, $input->gitBotEmail, $now);
        $project->changeIssueIdRegexp($input->issueIdRegexp, $now);
        $project->changeDisplaySubmoduleBranch($input->displaySubmoduleBranch, $now);
        $project->changeTypesense($input->typesense, $now);
    }
}
