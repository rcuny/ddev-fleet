<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\Host\HostSettings;
use App\Domain\User\UserRepository;
use App\Fleet\Instances\InstanceSiteRefresher;

/**
 * The step that must run before Authelia's configuration with the single "any signed-in user" rule is installed (FLE-100).
 * Until then the running Authelia still holds the old per-project rules and the running instance sites have no group
 * check; installing the relaxed rule first would let every signed-in user open every instance. So, in this order:
 *
 *   1. users.yml gets the project groups (the old rules ignore groups they do not use),
 *   2. every existing instance site is rewritten with the Remote-Groups check (and Caddy reloaded),
 *   3. only then may the caller install the new Authelia configuration.
 *
 * It fails closed: when any site cannot be rewritten it throws, the caller must not install Authelia, and the old
 * per-project rules keep protecting the sites. Before setup completes there is nothing to protect and nothing happens.
 */
final readonly class AccessModelUpgrade
{
    public function __construct(
        private UserRepository $users,
        private AutheliaUsersFile $usersFile,
        private InstanceSiteRefresher $sites,
    ) {
    }

    /**
     * @return int the number of instance sites rewritten
     *
     * @throws \RuntimeException when users.yml cannot be written or a site cannot be rewritten: do not install Authelia
     */
    public function prepare(HostSettings $host): int
    {
        if (!$host->isSetupComplete()) {
            return 0;
        }

        try {
            $this->usersFile->sync($this->users);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('users.yml cannot be written: '.$e->getMessage(), 0, $e);
        }

        $result = $this->sites->refresh();
        if ([] !== $result->failed) {
            throw new \RuntimeException(\sprintf('The Caddy site of %d instance(s) could not be rewritten with the project access check (%s). Authelia was left as it is, so the sites keep their old protection; fix the cause and run this again.', \count($result->failed), implode('; ', array_map(static fn (string $id, string $reason): string => $id.': '.$reason, array_keys($result->failed), $result->failed))));
        }

        return $result->rewritten;
    }
}
