<?php

declare(strict_types=1);

namespace App\Fleet\Typesense;

use App\Domain\Project\Project;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretStore;

/**
 * The two Typesense keys of a project, like 0.x `ensure_project_keys`: the admin key the instance's own Typesense
 * container boots with and the search-only key registered in it. Each is generated once (24 random bytes, 48 hex
 * characters), kept sealed as a project-managed secret and never rotated; a value an operator set by hand is kept.
 */
final readonly class TypesenseKeys
{
    public const string ADMIN = 'TYPESENSE_API_KEY';
    public const string SEARCH = 'FLEET_TYPESENSE_SEARCH_KEY';

    private const string UPDATED_BY = 'fleet';

    public function __construct(private SecretStore $secrets)
    {
    }

    /**
     * @throws \App\Fleet\Secrets\SecretOpenFailed when a stored key cannot be opened
     */
    public function ensure(Project $project): TypesenseKeySet
    {
        return new TypesenseKeySet(
            $this->one($project->name(), self::ADMIN),
            $this->one($project->name(), self::SEARCH),
        );
    }

    private function one(string $project, string $name): string
    {
        $existing = $this->secrets->getManaged($project, $name);
        if (null !== $existing && '' !== $existing) {
            return $existing;
        }

        $value = bin2hex(random_bytes(24));
        try {
            $this->secrets->putManaged($project, $name, $value, self::UPDATED_BY);
        } catch (SecretConflict) {
            // A concurrent deploy stored one first; both must use that one.
            return $this->secrets->getManaged($project, $name) ?? throw new \RuntimeException(\sprintf('The secret "%s" could not be stored.', $name));
        }

        return $value;
    }
}
