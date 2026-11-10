<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\Ssh\ManagedSshKey;
use App\Domain\Ssh\ManagedSshKeyRepository;
use App\Domain\Ssh\ManagedSshKeySource;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\Rule\SshKeyNames;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * The extra SSH keys Fleet manages (FLE-266): generate one, import one, delete one nobody uses. The private key is the
 * file `ssh/<name>_<type>` (`ed25519`, `ecdsa` or `rsa`, the key's real type; 0600) with its `.pub` (0644) next to it, written like the host's own keys; the database
 * keeps the public half and fingerprint only. The private key never enters the database, a log line, a return value or a
 * process argument. A key file placed by hand (no row) keeps working and is listed as "external".
 */
final readonly class ManagedSshKeys
{
    private const int MAX_IMPORT_BYTES = 16384;

    public function __construct(
        private FleetPaths $paths,
        private ManagedSshKeyRepository $keys,
        private HostSettingsRepository $settings,
        private Ed25519KeyGenerator $generator,
        private CommandRunner $runner,
        private ProjectRepository $projects,
        private TemplateRepository $templates,
        private AuditLog $audit,
        private SshKeysLock $lock,
    ) {
    }

    /**
     * @throws \InvalidArgumentException for an invalid name
     * @throws Refused                   when a key or a file of that name exists
     */
    public function create(string $name, string $by): ManagedSshKey
    {
        self::assertBy($by);
        self::assertName($name);

        $row = $this->locked(function () use ($name): ManagedSshKey {
            $this->assertFree($name);
            $key = $this->generator->generate($this->comment($name));
            $this->install($name, $key->privatePem(), $key->publicLine);

            return $this->saveRow($name, ManagedSshKeySource::Generated, $key->publicLine, $key->fingerprint);
        });
        $this->audit->record($by, AuditLog::SSH_KEY_CREATE, $name);

        return $row;
    }

    /**
     * @throws \InvalidArgumentException for an invalid name or a key that is not a usable unencrypted private key
     * @throws Refused                   when a key or a file of that name exists
     */
    public function import(string $name, #[\SensitiveParameter] string $pem, string $by): ManagedSshKey
    {
        self::assertBy($by);
        self::assertName($name);
        if (str_contains($pem, "\0")) {
            throw new \InvalidArgumentException('That is not an unencrypted SSH private key.');
        }
        $pem = str_replace("\r\n", "\n", trim($pem))."\n";
        if (\strlen($pem) > self::MAX_IMPORT_BYTES || 1 !== preg_match('/^-----BEGIN [A-Z ]*PRIVATE KEY-----\n/', $pem)) {
            throw new \InvalidArgumentException('That is not an unencrypted SSH private key.');
        }

        $row = $this->locked(function () use ($name, $pem): ManagedSshKey {
            $this->assertFree($name);
            $tmp = AtomicFile::tempPath($this->paths->sshDir(), '.import');
            try {
                AtomicFile::create($tmp, $pem, 0o600);
                $derived = $this->derivePublic($tmp);
            } finally {
                @unlink($tmp);
            }
            if (null === $derived) {
                throw new \InvalidArgumentException('The private key is invalid or protected by a passphrase.');
            }
            try {
                $suffix = SshPublicKeys::fileSuffix($derived['type']);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('That key type is not supported (use ed25519, ecdsa or rsa).');
            }
            $line = $derived['type'].' '.$derived['key'].' '.$this->comment($name);
            $this->install($name, $pem, $line, $suffix);

            return $this->saveRow($name, ManagedSshKeySource::Imported, $line, SshPublicKeys::fingerprint($derived['type'], $derived['key']));
        });
        $this->audit->record($by, AuditLog::SSH_KEY_IMPORT, $name);

        return $row;
    }

    /**
     * Removes a Fleet-managed key (file, .pub and row) when no template names it.
     *
     * @return string the name removed
     *
     * @throws Refused when it is unknown, external (a hand-placed file) or still used by a template
     */
    public function delete(string $name, string $by): string
    {
        self::assertBy($by);

        $this->locked(function () use ($name): void {
            $row = $this->keys->findByName($name);
            if (null === $row) {
                throw new Refused(\sprintf('"%s" is not a key Fleet manages. A key file placed by hand is removed by hand.', $name));
            }
            if ([] !== $users = $this->templatesUsing($name)) {
                throw new Refused(\sprintf('The SSH key "%s" is used by %s. Remove it from the template(s) first.', $name, implode(', ', $users)));
            }
            foreach (FleetPaths::sshExtraKeySuffixes() as $suffix) {
                @unlink($this->paths->sshExtraKey($name, $suffix));
                @unlink($this->paths->sshExtraKey($name, $suffix).'.pub');
            }
            $this->keys->remove($row);
        });
        $this->audit->record($by, AuditLog::SSH_KEY_DELETE, $name);

        return $name;
    }

    /**
     * @return list<string> `project/template` of every template that names the key
     */
    public function templatesUsing(string $name): array
    {
        $users = [];
        foreach ($this->projects->all() as $project) {
            foreach ($this->templates->forProject($project) as $template) {
                if (\in_array($name, $template->sshKeys(), true)) {
                    $users[] = $project->name().'/'.$template->name();
                }
            }
        }

        return $users;
    }

    private function assertFree(string $name): void
    {
        if (null !== $this->keys->findByName($name) || null !== $this->paths->existingSshExtraKey($name)) {
            throw new Refused(\sprintf('An SSH key named "%s" already exists on this host.', $name));
        }
    }

    private static function assertName(string $name): void
    {
        if (null !== $error = SshKeyNames::error([$name])) {
            throw new \InvalidArgumentException($error);
        }
    }

    private static function assertBy(string $by): void
    {
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The requester must be 1 to 64 bytes.');
        }
    }

    private function install(string $name, #[\SensitiveParameter] string $privatePem, string $publicLine, string $suffix = 'ed25519'): void
    {
        $private = $this->paths->sshExtraKey($name, $suffix);
        AtomicFile::write($private, $privatePem, 0o600);
        AtomicFile::write($private.'.pub', $publicLine."\n", 0o644);
    }

    private function saveRow(string $name, ManagedSshKeySource $source, string $publicLine, string $fingerprint): ManagedSshKey
    {
        $row = new ManagedSshKey($name, $source, $publicLine, $fingerprint);
        $this->keys->save($row);

        return $row;
    }

    /**
     * @return array{type: string, key: string}|null
     */
    private function derivePublic(string $privateKeyPath): ?array
    {
        try {
            $result = $this->runner->run(['ssh-keygen', '-y', '-P', '', '-f', $privateKeyPath], null, [], null, 15.0);
        } catch (CommandTimedOut|CommandRunnerException) {
            return null;
        }
        if (!$result->isSuccess()) {
            return null;
        }
        try {
            $parts = SshPublicKeys::parseLine(strtok($result->stdout, "\n") ?: '');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['type' => $parts['type'], 'key' => $parts['key']];
    }

    private function comment(string $name): string
    {
        $domain = $this->settings->get()?->domain();
        if (null === $domain || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $domain)) {
            $domain = 'localhost';
        }

        return \sprintf('fleet-%s@%s', $name, $domain);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(callable $work): mixed
    {
        // The same lock as SshKeyService and the template saves, so a rotation, an extra-key change and a template
        // save that names a key never interleave.
        return $this->lock->run($work);
    }
}
