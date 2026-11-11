<?php

declare(strict_types=1);

namespace App\Fleet\Audit;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use Psr\Clock\ClockInterface;

/**
 * Records who did what. The subject is a label, never a value: callers pass names of things, not their contents.
 */
final readonly class AuditLog
{
    public const string SECRET_PUT = 'secret.put';
    public const string SECRET_DELETE = 'secret.delete';
    public const string SECRET_REVEAL = 'secret.reveal';
    public const string SECRET_REVEAL_REFUSED = 'secret.reveal-refused';
    public const string ASSET_SNAPSHOT = 'asset.snapshot';
    public const string CLAUDE_TOKEN_ROTATE = 'claude.token.rotate';
    public const string CLAUDE_TOKEN_APPLY = 'claude.token.apply';

    public const string SCHEDULE_CREATE = 'schedule.create';
    public const string SCHEDULE_UPDATE = 'schedule.update';
    public const string SCHEDULE_DELETE = 'schedule.delete';
    public const string SCHEDULE_PAUSE = 'schedule.pause';
    public const string SCHEDULE_RESUME = 'schedule.resume';
    public const string SCHEDULE_RUN = 'schedule.run';

    public const string HOST_UPGRADE = 'host.upgrade';
    public const string HOST_PROVISION = 'host.provision';
    public const string HOST_DRIFT = 'host.drift';

    public const string REAUTH_SUCCESS = 'reauth.success';
    public const string REAUTH_FAILURE = 'reauth.failure';
    public const string REAUTH_REQUIRED = 'reauth.required';

    /** A project's CPU threads or slots allotment changed (the Adjust allotments sheet, FLE-318); one entry per project. */
    public const string PROJECT_CAPACITY = 'project.capacity';
    public const string PROJECT_EXPORT = 'project.export';
    public const string PROJECT_IMPORT = 'project.import';

    public const string DB_ISOLATION_DISCONNECT = 'isolation.db-disconnect';
    public const string PROJECT_CONTAINER_SSH = 'project.container-ssh';

    public const string SSH_KEY_CREATE = 'ssh.key.create';
    public const string SSH_KEY_IMPORT = 'ssh.key.import';
    public const string SSH_KEY_DELETE = 'ssh.key.delete';
    public const string SSH_KEY_ROTATE = 'ssh.key.rotate';

    public const string WEBHOOK_RULE_ADD = 'webhook.rule.add';
    public const string WEBHOOK_RULE_REMOVE = 'webhook.rule.remove';

    public const string API_TOKEN_CREATE = 'api_token.create';
    public const string API_TOKEN_ROTATE = 'api_token.rotate';
    public const string API_TOKEN_REVOKE = 'api_token.revoke';
    public const string API_TOKEN_EXPIRE = 'api_token.expire';
    /** A reminder: a token expires within the warning window (once a day per token, FLE-289). */
    public const string API_TOKEN_EXPIRING = 'api_token.expiring';
    /** A refused API call: bad, expired or revoked token, scope or project refusal, IP refusal, rate limit. */
    public const string API_DENIED = 'api.denied';
    /** A write the API accepted (deploy, redeploy, start, stop, destroy, retry); the subject names the target and the job. */
    public const string API_WRITE = 'api.write';
    /** A terminal read the API served: the subject holds the instance, pane, line and byte counts, never the text. */
    public const string API_TERMINAL_READ = 'api.terminal.read';

    /** Terminal input the API carried out; the subject holds the instance, pane, foreground command, length and the call id (the text is in `api_call`). */
    public const string API_TERMINAL_WRITE = 'api.terminal.write';
    /** An admin turned a project's API terminal input on or off. */
    public const string PROJECT_API_TERMINAL = 'project.api-terminal';

    /** An admin turned a project's privileged compose overrides on or off (FLE-298). */
    public const string PROJECT_PRIVILEGED_OVERRIDES = 'project.privileged-overrides';
    /** A deploy ran with the guard off for its project, with the violations it let through (FLE-298). */
    public const string DEPLOY_OVERRIDES_ALLOWED = 'deploy.overrides-allowed';
    /** The host's Docker socket was not root:docker 660 (FLE-298); the subject says what it was and whether it was restored. */
    public const string HOST_DOCKER_SOCKET = 'host.docker-socket';

    public const string PROJECT_MEMBER_ADD = 'project.member.add';
    public const string PROJECT_MEMBER_REMOVE = 'project.member.remove';

    private const string ELLIPSIS = '…';

    /** The actor of changes made on the command line. */
    public const string CLI_ACTOR = 'cli';

    public function __construct(private AuditEntryRepository $entries, private ClockInterface $clock)
    {
    }

    public function record(string $actor, string $action, string $subject): void
    {
        $this->entries->save(new AuditEntry(
            self::fit($actor, AuditEntry::ACTOR_MAX),
            self::fit($action, AuditEntry::ACTION_MAX),
            self::fit($subject, AuditEntry::SUBJECT_MAX),
            $this->clock->now(),
        ));
    }

    /** Whether $action was recorded with a subject starting with $subjectPrefix since $since. */
    public function recordedSince(string $action, string $subjectPrefix, \DateTimeImmutable $since): bool
    {
        return $this->entries->existsSince($action, $subjectPrefix, $since);
    }

    /**
     * @return list<AuditEntry>
     */
    public function latest(int $limit = 200, ?string $action = null): array
    {
        return $this->entries->latest($limit, $action);
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->entries->actions();
    }

    /**
     * Cuts $text to $max bytes on a character boundary and ends a cut text with an ellipsis, so a truncation shows.
     */
    private static function fit(string $text, int $max): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return mb_strcut($text, 0, $max - \strlen(self::ELLIPSIS)).self::ELLIPSIS;
    }
}
