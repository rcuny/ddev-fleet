<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Project\ProjectRepository;
use App\Domain\Ssh\ManagedSshKey;
use App\Domain\Ssh\ManagedSshKeyRepository;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Domain\Template\SshIdentities;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\Rule\SshKeyNames;

/**
 * Every SSH key the host knows (FLE-266): the deploy key, the push key and each extra key, with its purpose, fingerprint,
 * public half, where it is used and when it was created. Reads public data only; a hand-placed key (no row) is derived
 * in-process from its file and listed as "external".
 */
final readonly class SshKeyCatalog
{
    public const string DEPLOY_PURPOSE = 'Clones and fetches your projects\' repositories on this host (read access). Never given to an instance.';
    public const string PUSH_PURPOSE = 'Lets an instance push to your remotes. Loaded into that instance\'s own ssh-agent, only for projects with Git over SSH in the container; never into DDEV\'s shared agent.';
    public const string EXTRA_PURPOSE = 'An extra key a template asks for, loaded into the instance\'s own ssh-agent (next to the push key, or instead of it).';

    public function __construct(
        private FleetPaths $paths,
        private SshKeyRepository $hostKeys,
        private ManagedSshKeyRepository $managed,
        private ExtraSshKey $extraFile,
        private ProjectRepository $projects,
        private TemplateRepository $templates,
    ) {
    }

    /**
     * @return list<SshKeyEntry> deploy, push, then the extra keys by name
     */
    public function entries(): array
    {
        $pushUsers = [];
        $extraUsers = [];
        foreach ($this->projects->all() as $project) {
            foreach ($this->templates->forProject($project) as $template) {
                $label = $project->name().'/'.$template->name();
                if ($project->containerSsh() && SshIdentities::PushAndExtras === $template->sshIdentities()) {
                    $pushUsers[] = $label;
                }
                foreach ($template->sshKeys() as $name) {
                    $extraUsers[$name][] = $label;
                }
            }
        }

        $entries = [
            $this->hostEntry(SshKeyRole::Deploy, self::DEPLOY_PURPOSE, ['every project: the host\'s own clones and fetches']),
            $this->hostEntry(SshKeyRole::Push, self::PUSH_PURPOSE, $pushUsers),
        ];

        $rows = [];
        foreach ($this->managed->all() as $row) {
            $rows[$row->name()] = $row;
        }
        $names = array_keys($rows);
        foreach ($this->externalNames() as $name) {
            $names[] = $name;
        }
        // A template may name a key that does not exist (yet): it is listed too, with the problem.
        foreach (array_keys($extraUsers) as $name) {
            $names[] = (string) $name;
        }
        $names = array_values(array_unique($names));
        sort($names);

        foreach ($names as $name) {
            $entries[] = $this->extraEntry($name, $rows[$name] ?? null, $extraUsers[$name] ?? []);
        }

        return $entries;
    }

    /**
     * @param list<string> $usedBy
     */
    private function hostEntry(SshKeyRole $role, string $purpose, array $usedBy): SshKeyEntry
    {
        $row = $this->hostKeys->findByRole($role);

        return new SshKeyEntry(
            $role->value,
            $role->value,
            'host',
            $purpose,
            $row?->publicKey(),
            $row?->fingerprint(),
            $usedBy,
            $row?->createdAt(),
            null === $row ? 'The host has no '.$role->value.' key yet (run the setup wizard or `fleet host:init`).' : null,
        );
    }

    /**
     * @param list<string> $usedBy
     */
    private function extraEntry(string $name, ?ManagedSshKey $row, array $usedBy): SshKeyEntry
    {
        $problem = $this->extraFile->problem($name);
        if (null !== $row) {
            return new SshKeyEntry($name, 'extra', $row->source()->value, self::EXTRA_PURPOSE, $row->publicKey(), $row->fingerprint(), $usedBy, $row->createdAt(), $problem);
        }

        $derived = $this->externalPublic($name);

        return new SshKeyEntry(
            $name,
            'extra',
            'external',
            self::EXTRA_PURPOSE,
            null === $derived ? null : $derived['type'].' '.$derived['key'],
            null === $derived ? null : SshPublicKeys::fingerprint($derived['type'], $derived['key']),
            $usedBy,
            $this->mtime($name),
            $problem,
        );
    }

    /**
     * @return list<string> names of `ssh/<name>_(ed25519|ecdsa|rsa)` files that are valid extra key names
     */
    private function externalNames(): array
    {
        $names = [];
        foreach (FleetPaths::sshExtraKeySuffixes() as $suffix) {
            foreach (glob($this->paths->sshDir().'/*_'.$suffix) ?: [] as $file) {
                $name = substr(basename($file), 0, -\strlen('_'.$suffix));
                if (null === SshKeyNames::error([$name])) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{type: string, key: string}|null
     */
    private function externalPublic(string $name): ?array
    {
        $file = $this->extraFile->path($name);
        if (is_link($file) || !is_file($file)) {
            return null;
        }
        $derived = OpensshPrivateKey::publicKey((string) @file_get_contents($file));
        if (null !== $derived) {
            return $derived;
        }
        $pub = is_file($file.'.pub') && !is_link($file.'.pub') ? (string) @file_get_contents($file.'.pub') : '';
        try {
            $parts = SshPublicKeys::parseLine(strtok($pub, "\n") ?: '');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['type' => $parts['type'], 'key' => $parts['key']];
    }

    private function mtime(string $name): ?\DateTimeImmutable
    {
        $time = @filemtime($this->extraFile->path($name));

        return false === $time ? null : new \DateTimeImmutable('@'.$time);
    }
}
