<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Auth\AccessInstallFailed;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\InvalidUserInput;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserChangeRefused;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the `fleet user:*` commands share. They use the exit codes of the project commands (JsonOutput): 0 done, 1 a value
 * was refused or the change could not be written (nothing was changed), 2 not allowed now (the last enabled administrator,
 * an unconfirmed delete), 3 the user or project does not exist. A change is saved and followed by an Authelia install; when
 * only that install fails the exit code stays 0 and a warning is shown (the change stands, as in the pages).
 */
abstract class AbstractUserCommand extends Command
{
    protected static function output(InputInterface $input, OutputInterface $output): JsonOutput
    {
        return JsonOutput::of($input, $output, FieldErrors::user());
    }

    /**
     * Runs the action and maps the service exceptions to an exit code (the project exceptions are mapped by JsonOutput).
     *
     * @param callable(): void $action
     */
    protected static function guarded(JsonOutput $out, callable $action): int
    {
        try {
            return $out->run($action);
        } catch (UnknownUser $e) {
            return $out->fail($e->getMessage(), JsonOutput::NOT_FOUND);
        } catch (UserChangeRefused $e) {
            return $out->fail($e->getMessage(), JsonOutput::REFUSED);
        } catch (InvalidUserInput $e) {
            // The password is not an option (it comes from the prompt or stdin), so its refusal has no option to name.
            return $out->fail($e->getMessage(), JsonOutput::INVALID, 'password' === $e->field ? [] : [FieldErrors::user()->key($e->field) => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return $out->fail($e->getMessage(), JsonOutput::INVALID);
        } catch (\RuntimeException $e) {
            return $out->fail('Nothing was changed: '.$e->getMessage(), JsonOutput::INVALID);
        }
    }

    /**
     * IdentitySync::commit(), with a failed Authelia install turned into a warning.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return array{T, ?string} what the change returned, and the warning when only the install failed
     */
    protected static function commit(IdentitySync $sync, callable $change): array
    {
        try {
            return [$sync->commit($change), null];
        } catch (AccessInstallFailed $e) {
            /** @var T $result */
            $result = $e->result;

            return [$result, $e->getMessage()];
        }
    }
}
