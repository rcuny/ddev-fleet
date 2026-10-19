<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Project\Project;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\Rule\IssueIdPattern;

/**
 * Pure: reads the event and the rules and says which rule matched, with the label and branch the action would use.
 * The 0.x semantics are kept: Jira matches the status TRANSITION in the changelog (not the issue's current status), the
 * first rule in order wins, Bitbucket rules combine event, repository, branch glob, status state and comment line.
 */
final class RuleMatcher
{
    private const int LABEL_MAX = 40;

    /**
     * @param array<string, string> $headers lower-case name => value
     * @param array<mixed>          $payload the decoded JSON object
     * @param list<WebhookRule>     $rules
     *
     * @throws PayloadInvalid
     */
    public function match(WebhookSource $source, Project $project, array $headers, array $payload, array $rules): RuleOutcome
    {
        return match ($source) {
            WebhookSource::Jira => $this->jira($project, $payload, $rules),
            WebhookSource::Bitbucket => $this->bitbucket($project, $headers, $payload, $rules),
        };
    }

    /**
     * @param array<mixed>      $payload
     * @param list<WebhookRule> $rules
     */
    private function jira(Project $project, array $payload, array $rules): RuleOutcome
    {
        $event = self::string($payload['webhookEvent'] ?? null) ?? 'unknown';
        $issue = $payload['issue'] ?? null;
        $key = \is_array($issue) ? self::string($issue['key'] ?? null) : null;
        if ('jira:issue_updated' === $event && null === $key) {
            throw new PayloadInvalid('The event has no issue key.');
        }
        if ('jira:issue_updated' !== $event) {
            return RuleOutcome::ignored($event, $key, null, 'event is not an issue update');
        }

        $transition = self::statusTransition($payload);
        if (null === $transition) {
            return RuleOutcome::ignored($event, $key, null, 'no status change');
        }
        [$from, $to] = $transition;
        $change = $from.' -> '.$to;

        $pattern = $project->issueIdRegexp();
        // The pattern is operator input run on a stranger's text: IssueIdPattern is the one safe way to apply it.
        if (null !== $pattern && 0 !== strcasecmp((string) IssueIdPattern::extract($pattern, (string) $key, ''), (string) $key)) {
            return RuleOutcome::ignored($event, $key, $change, 'issue key does not match the project issue id pattern');
        }

        foreach ($rules as $rule) {
            if (0 !== strcasecmp($rule->match()['on_status'] ?? '', $to)) {
                continue;
            }
            $label = self::label((string) $key);
            $branch = $rule->params()['branch'] ?? $project->defaultBranch();

            return new RuleOutcome($event, $key, $change, $rule, null, $label, $branch, self::deployText($rule, $label, $branch));
        }

        return RuleOutcome::ignored($event, $key, $change, 'no rule for this status');
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>          $payload
     * @param list<WebhookRule>     $rules
     */
    private function bitbucket(Project $project, array $headers, array $payload, array $rules): RuleOutcome
    {
        $event = $headers['x-event-key'] ?? '';
        if ('' === $event) {
            throw new PayloadInvalid('The X-Event-Key header is missing.');
        }

        $repo = self::path($payload, ['repository', 'full_name']);
        $branch = self::path($payload, ['pullrequest', 'source', 'branch', 'name'])
            ?? self::path($payload, ['commit_status', 'refname'])
            ?? self::path($payload, ['push', 'changes', 0, 'new', 'name']);
        $state = self::path($payload, ['commit_status', 'state']);
        $comment = self::path($payload, ['comment', 'content', 'raw']);
        $change = null === $state ? null : 'state '.$state;

        foreach ($rules as $rule) {
            $match = $rule->match();
            if (($match['on_event'] ?? '') !== $event
                || 0 !== strcasecmp($match['repo'] ?? '', $repo ?? '')
                || (isset($match['branch']) && (null === $branch || !fnmatch($match['branch'], $branch)))
                || (isset($match['state']) && 0 !== strcasecmp($match['state'], $state ?? ''))
                || (isset($match['comment']) && !self::hasLine($comment, $match['comment']))) {
                continue;
            }
            $params = $rule->params();
            if (WebhookRule::ACTION_DEPLOY === $rule->action()) {
                $label = $params['label'] ?? self::label((string) $branch);

                return new RuleOutcome($event, $branch, $change, $rule, null, $label, $branch, self::deployText($rule, $label, $branch));
            }
            $ref = $params['ref'] ?? $project->defaultBranch();
            $text = \sprintf('would run pipeline %s on %s (%s)', $params['pattern'] ?? '?', $ref ?? '?', $repo ?? '?');

            return new RuleOutcome($event, $branch, $change, $rule, null, null, $ref, $text);
        }

        return RuleOutcome::ignored($event, $branch, $change, 'no rule matches this event');
    }

    private static function deployText(WebhookRule $rule, ?string $label, ?string $branch): string
    {
        $text = \sprintf('would deploy %s (template %s', $label ?? '?', $rule->params()['template'] ?? '?');

        return $text.(null === $branch ? ')' : ', branch '.$branch.')');
    }

    /**
     * The last status change of the changelog: [from, to].
     *
     * @param array<mixed> $payload
     *
     * @return array{string, string}|null
     */
    private static function statusTransition(array $payload): ?array
    {
        $changelog = $payload['changelog'] ?? null;
        $items = \is_array($changelog) ? ($changelog['items'] ?? null) : null;
        if (!\is_array($items)) {
            return null;
        }
        $found = null;
        foreach ($items as $item) {
            if (\is_array($item) && 'status' === ($item['field'] ?? null)) {
                $to = self::string($item['toString'] ?? null);
                if (null !== $to) {
                    $found = [self::string($item['fromString'] ?? null) ?? '', $to];
                }
            }
        }

        return $found;
    }

    /**
     * An instance label from an issue key or branch: lower case, letters, digits and single dashes.
     */
    public static function label(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return rtrim(substr($slug, 0, self::LABEL_MAX), '-');
    }

    private static function hasLine(?string $comment, string $wanted): bool
    {
        foreach (preg_split('/\R/', (string) $comment) ?: [] as $line) {
            if (0 === strcasecmp(trim($line), $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $path
     */
    private static function path(array $data, array $path): ?string
    {
        $value = $data;
        foreach ($path as $step) {
            if (!\is_array($value) || !\array_key_exists($step, $value)) {
                return null;
            }
            $value = $value[$step];
        }

        return self::string($value);
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
