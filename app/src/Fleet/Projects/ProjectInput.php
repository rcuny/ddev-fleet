<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;

/**
 * What a form or a command line submits for a project. A page that edits only some of the fields starts from
 * fromProject() and replaces those with with(), so saving it never wipes what it does not show (port assignments).
 */
final readonly class ProjectInput
{
    /**
     * @param list<string> $additionalHostnames extra host name labels
     * @param list<string> $ports               names of the catalogue entries assigned to the project
     */
    public function __construct(
        public string $name,
        public string $git,
        public ?string $defaultBranch = null,
        public ?string $defaultTemplate = null,
        public array $additionalHostnames = [],
        public array $ports = [],
        public GitBotMode $gitBotMode = GitBotMode::Inherit,
        public ?string $gitBotName = null,
        public ?string $gitBotEmail = null,
        public ?string $issueIdRegexp = null,
        public ?string $displaySubmoduleBranch = null,
        public bool $typesense = false,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(
            $project->name(),
            $project->git(),
            $project->defaultBranch(),
            $project->defaultTemplate(),
            $project->additionalHostnames(),
            array_map(static fn (NamedPort $port): string => $port->name(), $project->ports()),
            $project->gitBotMode(),
            $project->gitBotName(),
            $project->gitBotEmail(),
            $project->issueIdRegexp(),
            $project->displaySubmoduleBranch(),
            $project->typesense(),
        );
    }

    /**
     * A copy with some fields replaced.
     *
     * @param array<string, mixed> $changes new values by property name
     */
    public function with(array $changes): self
    {
        $unknown = array_diff(array_keys($changes), array_keys(get_object_vars($this)));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown project input field(s): %s.', implode(', ', $unknown)));
        }

        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * Trims every text (a pasted git URL carries a newline), turns blank optional values into null, drops blank and
     * repeated port names and blank hostnames, and drops the git bot name and email unless the mode is Override.
     */
    public function normalised(): self
    {
        $override = GitBotMode::Override === $this->gitBotMode;

        return new self(
            trim($this->name),
            trim($this->git),
            self::blankToNull($this->defaultBranch),
            self::blankToNull($this->defaultTemplate),
            self::cleanList($this->additionalHostnames),
            array_values(array_unique(self::cleanList($this->ports))),
            $this->gitBotMode,
            $override ? self::blankToNull($this->gitBotName) : null,
            $override ? self::blankToNull($this->gitBotEmail) : null,
            self::blankToNull($this->issueIdRegexp),
            self::blankToNull($this->displaySubmoduleBranch),
            $this->typesense,
        );
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function cleanList(array $values): array
    {
        $kept = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ('' !== $value) {
                $kept[] = $value;
            }
        }

        return $kept;
    }
}
