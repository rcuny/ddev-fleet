<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Ssh\SshKey;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * The host's two SSH identities (`deploy` clones repositories, `push` pushes to remotes): generated in-process,
 * stored as FleetPaths::sshPrivateKey() (0600) and its `.pub` (0644) in the 0700 ssh directory, with the public key
 * and fingerprint mirrored in the database. The private key never enters the database, a log line, a return value
 * or a process argument.
 */
final readonly class SshKeyService
{
    /** A passphrase-less RSA-8192 key is about 6.5 KiB; anything much bigger is not a private key. */
    private const int MAX_IMPORT_BYTES = 16384;

    public function __construct(
        private FleetPaths $paths,
        private SshKeyRepository $keys,
        private HostSettingsRepository $settings,
        private Ed25519KeyGenerator $generator,
        private CommandRunner $runner,
    ) {
    }

    /**
     * Makes sure both roles have a key pair on disk and a row. Idempotent: existing keys are kept (modes are
     * repaired, a missing row or .pub is recreated from what is on disk); only a role with no private key gets a
     * new pair.
     */
    public function ensureKeys(): void
    {
        foreach (SshKeyRole::cases() as $role) {
            $this->ensureRole($role);
        }
    }

    public function publicKey(SshKeyRole $role): string
    {
        return $this->row($role)->publicKey();
    }

    public function fingerprint(SshKeyRole $role): string
    {
        return $this->row($role)->fingerprint();
    }

    /**
     * Replaces the role's key pair with a freshly generated one. The old private key is gone afterwards.
     *
     * @param string $by who asked (reserved for the audit trail)
     */
    public function regenerate(SshKeyRole $role, string $by): void
    {
        self::assertBy($by);
        $this->ensureSshDir();

        $key = $this->generator->generate($this->comment($role));
        $this->install($role, $key->privatePem, $key->publicLine);
        $this->saveRow($role, $key->publicLine, $key->fingerprint);
    }

    /**
     * Installs an existing unencrypted private key. The public half is derived by `ssh-keygen -y` from a private
     * temp file that is always removed.
     *
     * @param string $by who asked (reserved for the audit trail)
     *
     * @throws \InvalidArgumentException when the key is not a usable unencrypted private key
     */
    public function importPrivate(SshKeyRole $role, #[\SensitiveParameter] string $pem, string $by): void
    {
        self::assertBy($by);
        // NUL is checked before trim(), which would strip a trailing one.
        if (str_contains($pem, "\0")) {
            throw new \InvalidArgumentException('That is not an unencrypted SSH private key.');
        }
        $pem = str_replace("\r\n", "\n", trim($pem))."\n";
        if (\strlen($pem) > self::MAX_IMPORT_BYTES || 1 !== preg_match('/^-----BEGIN [A-Z ]*PRIVATE KEY-----\n/', $pem)) {
            throw new \InvalidArgumentException('That is not an unencrypted SSH private key.');
        }
        $this->ensureSshDir();

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

        $publicLine = $derived['type'].' '.$derived['key'].' '.$this->comment($role);
        $this->install($role, $pem, $publicLine);
        $this->saveRow($role, $publicLine, SshPublicKeys::fingerprint($derived['type'], $derived['key']));
    }

    private function ensureRole(SshKeyRole $role): void
    {
        $this->ensureSshDir();
        $private = $this->paths->sshPrivateKey($role);
        $public = $this->paths->sshPublicKey($role);

        if (!is_file($private) && !is_link($private)) {
            $key = $this->generator->generate($this->comment($role));
            $this->install($role, $key->privatePem, $key->publicLine);
            $this->saveRow($role, $key->publicLine, $key->fingerprint);

            return;
        }
        if (is_link($private) || is_link($public)) {
            throw new \RuntimeException(\sprintf('The %s SSH key must not be a symlink.', $role->value));
        }
        if (!@chmod($private, 0o600)) {
            throw new \RuntimeException(\sprintf('Cannot set mode 0600 on the %s SSH private key.', $role->value));
        }

        $line = $this->readPublicLine($public);
        if (null === $line) {
            // The private key survived but its .pub did not: derive it, never overwrite the private key.
            $derived = $this->derivePublic($private);
            if (null === $derived) {
                throw new \RuntimeException(\sprintf('The existing %s SSH private key is unusable; import or regenerate it.', $role->value));
            }
            $line = $derived['type'].' '.$derived['key'].' '.$this->comment($role);
            AtomicFile::write($public, $line."\n", 0o644);
        } elseif ((@fileperms($public) & 0o777) !== 0o644 && !@chmod($public, 0o644)) {
            throw new \RuntimeException(\sprintf('Cannot set mode 0644 on the %s SSH public key.', $role->value));
        }

        $parts = SshPublicKeys::parseLine($line);
        $this->saveRow($role, $line, SshPublicKeys::fingerprint($parts['type'], $parts['key']));
    }

    /**
     * @return array{type: string, key: string}|null null when ssh-keygen rejects the key (invalid, or it needs a passphrase)
     */
    private function derivePublic(string $privateKeyPath): ?array
    {
        try {
            // -P '' = empty passphrase and never prompt: an encrypted key fails instead of hanging.
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

    private function readPublicLine(string $path): ?string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if (false === $contents) {
            return null;
        }
        try {
            $parts = SshPublicKeys::parseLine(strtok($contents, "\n") ?: '');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $parts['type'].' '.$parts['key'].('' === $parts['comment'] ? '' : ' '.$parts['comment']);
    }

    /**
     * Private key first (0600), then the public one (0644); each replaced atomically.
     */
    private function install(SshKeyRole $role, #[\SensitiveParameter] string $privatePem, string $publicLine): void
    {
        AtomicFile::write($this->paths->sshPrivateKey($role), $privatePem, 0o600);
        AtomicFile::write($this->paths->sshPublicKey($role), $publicLine."\n", 0o644);
    }

    private function saveRow(SshKeyRole $role, string $publicLine, string $fingerprint): void
    {
        $row = $this->keys->findByRole($role);
        if (null === $row) {
            $this->keys->save(new SshKey($role, $publicLine, $fingerprint));
        } elseif ($row->publicKey() !== $publicLine || $row->fingerprint() !== $fingerprint) {
            $row->replaceKey($publicLine, $fingerprint);
            $this->keys->save($row);
        }
    }

    private function row(SshKeyRole $role): SshKey
    {
        return $this->keys->findByRole($role)
            ?? throw new \RuntimeException(\sprintf('The %s SSH key does not exist yet; call ensureKeys() first.', $role->value));
    }

    private function comment(SshKeyRole $role): string
    {
        $domain = $this->settings->get()?->domain();
        if (null === $domain || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $domain)) {
            $domain = 'localhost';
        }

        return \sprintf('fleet-%s@%s', $role->value, $domain);
    }

    private function ensureSshDir(): void
    {
        $dir = $this->paths->sshDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }
        if ((@fileperms($dir) & 0o777) !== 0o700 && !@chmod($dir, 0o700)) {
            throw new \RuntimeException(\sprintf('Cannot set mode 0700 on "%s".', $dir));
        }
    }

    private static function assertBy(string $by): void
    {
        if ('' === $by || \strlen($by) > 64) {
            throw new \InvalidArgumentException('The requester must be 1 to 64 bytes.');
        }
    }
}
