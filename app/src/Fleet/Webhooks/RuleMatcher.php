<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Project\Project;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Instances\Labels;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Fleet\Projects\ValidationFailed;

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
     * @throws TargetUnresolvable
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
            $targets = null === $branch ? [] : [new DeployTarget($rule, $branch, self::fit($project, $label, (string) $key))];

            return new RuleOutcome($event, $key, $change, $rule, null, $label, $branch, self::deployText($rule, $label, $branch), $targets);
        }

        return RuleOutcome::ignored($event, $key, $change, 'no rule for this status');
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>          $payload
     * @param list<WebhookRule>     $rules
     *
     * @throws TargetUnresolvable
     */
    private function bitbucket(Project $project, array $headers, array $payload, array $rules): RuleOutcome
    {
        $event = $headers['x-event-key'] ?? '';
        if ('' === $event) {
            throw new PayloadInvalid('The X-Event-Key header is missing.');
        }

        $repo = self::path($payload, ['repository', 'full_name']);
        $state = self::path($payload, ['commit_status', 'state']);
        $comment = self::path($payload, ['comment', 'content', 'raw']);
        $change = null === $state ? null : 'state '.$state;

        if ('repo:push' === $event) {
            return $this->push($project, $event, $repo, $payload, $rules);
        }

        $branch = self::path($payload, ['pullrequest', 'source', 'branch', 'name'])
            ?? self::path($payload, ['commit_status', 'refname']);
        foreach ($rules as $rule) {
            if (!self::ruleMatches($rule, $event, $repo, $branch, $state, $comment)) {
                continue;
            }

            return $this->forRule($project, $rule, $event, $repo, $branch, $change);
        }

        return RuleOutcome::ignored($event, $branch, $change, 'no rule matches this event');
    }

    /**
     * A push names one entry per ref in `push.changes`. Each created or updated BRANCH is matched on its own (first
     * rule wins, per branch); deletions and tags are skipped, so a push that only deletes is ignored. A run-pipeline
     * rule that matches ends the search, as before: one pipeline per event.
     *
     * @param array<mixed>      $payload
     * @param list<WebhookRule> $rules
     *
     * @throws TargetUnresolvable
     */
    private function push(Project $project, string $event, ?string $repo, array $payload, array $rules): RuleOutcome
    {
        $branches = self::pushedBranches($payload);
        if ([] === $branches) {
            // Nothing created or updated (a deletion, a tag): a rule with no branch condition may still want the event.
            foreach ($rules as $rule) {
                if (WebhookRule::ACTION_RUN_PIPELINE === $rule->action() && self::ruleMatches($rule, $event, $repo, null, null, null)) {
                    return $this->forRule($project, $rule, $event, $repo, null, null);
                }
            }

            return RuleOutcome::ignored($event, null, null, 'no branch was created or updated');
        }

        $targets = [];
        $skipped = [];
        $fixed = self::fixedLabels($rules);
        foreach ($branches as $branch) {
            foreach ($rules as $rule) {
                if (!self::ruleMatches($rule, $event, $repo, $branch, null, null)) {
                    continue;
                }
                if (WebhookRule::ACTION_DEPLOY !== $rule->action()) {
                    return $this->forRule($project, $rule, $event, $repo, $branch, null);
                }
                $target = $this->deployTarget($project, $rule, $branch);
                if (!$target->replace && \in_array($target->label, $fixed, true)) {
                    $skipped[] = ['subject' => $branch, 'reason' => 'collides with a fixed-label rule'];
                } elseif (!self::hasTarget($targets, $target)) {
                    $targets[] = $target;
                }
                break;
            }
        }

        if ([] === $targets) {
            $reason = [] === $skipped ? 'no rule matches this event' : 'collides with a fixed-label rule';

            return RuleOutcome::ignored($event, $branches[0], null, $reason);
        }

        $decisions = array_map(static fn (DeployTarget $t): string => self::deployText($t->rule, $t->label, $t->branch), $targets);
        $first = $targets[0];

        return new RuleOutcome($event, $first->branch, null, $first->rule, null, $first->label, $first->branch, implode('; ', $decisions), $targets, $skipped);
    }

    private function forRule(Project $project, WebhookRule $rule, string $event, ?string $repo, ?string $branch, ?string $change): RuleOutcome
    {
        if (WebhookRule::ACTION_DEPLOY === $rule->action()) {
            if (null === $branch) {
                throw new TargetUnresolvable('The event names no branch to deploy.');
            }
            $target = $this->deployTarget($project, $rule, $branch);

            return new RuleOutcome($event, $branch, $change, $rule, null, $target->label, $branch, self::deployText($rule, $target->label, $branch), [$target]);
        }

        $params = $rule->params();
        $ref = $params['ref'] ?? $project->defaultBranch();
        $text = \sprintf('run pipeline %s on %s (%s)', $params['pattern'] ?? '?', $ref ?? '?', $repo ?? '?');

        return new RuleOutcome($event, $branch, $change, $rule, null, null, $ref, $text);
    }

    /**
     * The instance a deploy rule makes of a branch: a fixed `label`, or `label_prefix` + the branch minus
     * `strip_suffix`, slugified, then fitted to 63 characters (the project's additional hostname aliases included; a
     * derived label keeps room for a `-999` suffix).
     *
     * @throws TargetUnresolvable
     */
    private function deployTarget(Project $project, WebhookRule $rule, string $branch): DeployTarget
    {
        $params = $rule->params();
        $alias = InstanceHostnames::longestPrefix($project);
        try {
            if (isset($params['label'])) {
                $label = InstanceNaming::fitLabel($project->name(), Labels::slug($params['label']), $branch, $alias);

                return new DeployTarget($rule, $branch, $label, true);
            }
            $text = $branch;
            $suffix = $params['strip_suffix'] ?? '';
            if ('' !== $suffix && str_ends_with($text, $suffix)) {
                $text = substr($text, 0, -\strlen($suffix));
            }
            $label = InstanceNaming::fitLabel($project->name(), Labels::slug(($params['label_prefix'] ?? '').$text), $branch, $alias, 4);
        } catch (ValidationFailed) {
            throw new TargetUnresolvable('The branch cannot be turned into an instance label.');
        }

        return new DeployTarget($rule, $branch, $label);
    }

    private static function fit(Project $project, string $label, string $seed): string
    {
        try {
            return InstanceNaming::fitLabel($project->name(), $label, $seed, InstanceHostnames::longestPrefix($project));
        } catch (ValidationFailed) {
            throw new TargetUnresolvable('The instance label does not fit the project name.');
        }
    }

    /**
     * @param list<DeployTarget> $targets
     */
    private static function hasTarget(array $targets, DeployTarget $target): bool
    {
        foreach ($targets as $known) {
            if ($known->label === $target->label) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fixed labels of the deploy rules (slugified as the targets are).
     *
     * @param list<WebhookRule> $rules
     *
     * @return list<string>
     */
    private static function fixedLabels(array $rules): array
    {
        $labels = [];
        foreach ($rules as $rule) {
            if (WebhookRule::ACTION_DEPLOY === $rule->action() && isset($rule->params()['label'])) {
                $labels[] = $rule->params()['label'];
            }
        }

        return $labels;
    }

    /**
     * The names of the branches a push created or updated, each once, in payload order.
     *
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private static function pushedBranches(array $payload): array
    {
        $push = $payload['push'] ?? null;
        $changes = \is_array($push) ? ($push['changes'] ?? null) : null;
        if (!\is_array($changes)) {
            return [];
        }
        $branches = [];
        foreach ($changes as $change) {
            $new = \is_array($change) ? ($change['new'] ?? null) : null;
            if (!\is_array($new) || 'branch' !== ($new['type'] ?? null)) {
                continue;
            }
            $name = self::string($new['name'] ?? null);
            if (null !== $name && !\in_array($name, $branches, true)) {
                $branches[] = $name;
            }
        }

        return $branches;
    }

    private static function ruleMatches(WebhookRule $rule, string $event, ?string $repo, ?string $branch, ?string $state, ?string $comment): bool
    {
        $match = $rule->match();

        return ($match['on_event'] ?? '') === $event
            && 0 === strcasecmp($match['repo'] ?? '', $repo ?? '')
            && (!isset($match['branch']) || (null !== $branch && fnmatch($match['branch'], $branch)))
            && (!isset($match['state']) || 0 === strcasecmp($match['state'], $state ?? ''))
            && (!isset($match['comment']) || self::hasLine($comment, $match['comment']));
    }

    private static function deployText(WebhookRule $rule, ?string $label, ?string $branch): string
    {
        $text = \sprintf('deploy %s (template %s', $label ?? '?', $rule->params()['template'] ?? '?');

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
        return rtrim(substr(Labels::slugOrNull($text) ?? '', 0, self::LABEL_MAX), '-');
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
