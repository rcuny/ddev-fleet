<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The extra SSH keys a template names (FLE-190): each one is the stem of a file `ssh/<name>_ed25519` on the host. Only the
 * name is checked here (it becomes part of a path and of an `ssh-add` argument, so it is an allow-list); the file itself
 * is checked by App\Fleet\Ssh\ExtraSshKey. The two keys the host owns can never be named: the deploy key clones, the push
 * key is always loaded for a project that opted in.
 */
final class SshKeyNames
{
    public const int MAX_KEYS = 8;
    public const int MAX_LENGTH = 32;

    /** Names that belong to SshKeyRole. */
    public const array RESERVED = ['deploy', 'push'];

    private const string PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    /**
     * @param list<string> $names
     */
    public static function error(array $names): ?string
    {
        if (\count($names) > self::MAX_KEYS) {
            return \sprintf('Name at most %d SSH keys.', self::MAX_KEYS);
        }
        foreach ($names as $name) {
            if (\in_array($name, self::RESERVED, true)) {
                return \sprintf('"%s" is a key the fleet owns and cannot be named here.', $name);
            }
            if (\strlen($name) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $name)) {
                return \sprintf('Key names are lower case letters, digits and single dashes, starting with a letter, at most %d characters.', self::MAX_LENGTH);
            }
        }
        if (\count($names) !== \count(array_unique($names))) {
            return 'Each SSH key can be named once.';
        }

        return null;
    }
}
