<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\ValidationFailed;

/**
 * The only code that creates and rotates receivers and adds or removes their rules (the pages and the commands call it).
 * Each failure is one of the project services' exceptions: ValidationFailed (field => message), Refused, NotFound.
 */
final readonly class WebhookService
{
    private const string EVENT_PATTERN = '/^(?:pullrequest|repo):[a-z_]{1,40}$/D';
    private const string REPO_PATTERN = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D';
    private const string GLOB_PATTERN = '/^[A-Za-z0-9._\/*?-]{1,200}$/D';
    private const string PIPELINE_PATTERN = '/^[A-Za-z0-9_.-]{1,100}$/D';
    private const string STATE_PATTERN = '/^[A-Za-z_]{1,40}$/D';
    private const string LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/D';
    private const string LABEL_PREFIX_PATTERN = '/^[a-z0-9][a-z0-9-]{0,19}$/D';
    private const string SUFFIX_PATTERN = '/^[A-Za-z0-9._\/-]{1,60}$/D';
    private const string TOKEN_PATTERN = '/^[\x21-\x7E]{1,2048}$/D';
    private const int TEXT_MAX = 200;

    public function __construct(
        private ProjectRepository $projects,
        private WebhookRepository $webhooks,
        private WebhookSecrets $secrets,
    ) {
    }

    /**
     * Creates the receiver, or replaces its secret when $rotate. Returns the secret, which is shown once.
     *
     * @throws NotFound
     * @throws Refused  when a secret exists already and $rotate is false
     */
    public function issueSecret(string $projectName, WebhookSource $source, bool $rotate): string
    {
        $project = $this->project($projectName);
        if (!$rotate && null !== $this->webhooks->endpoint($projectName, $source)) {
            throw new Refused(\sprintf('%s already has a webhook secret for %s. Rotate it to replace it (the old one stops working).', $projectName, $source->label()));
        }

        return $this->secrets->issue($project, $source);
    }

    /**
     * @param array<string, string> $match  the conditions, under their 0.x key names
     * @param array<string, string> $params what the action needs
     *
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function addRule(string $projectName, WebhookSource $source, string $action, array $match, array $params): WebhookRule
    {
        $project = $this->project($projectName);
        $endpoint = $this->webhooks->endpoint($projectName, $source)
            ?? throw new NotFound(\sprintf('%s has no %s webhook yet: create its secret first.', $projectName, $source->label()));

        $errors = WebhookSource::Jira === $source
            ? $this->jiraErrors($project, $endpoint, $action, $match, $params)
            : $this->bitbucketErrors($project, $action, $match, $params);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $rule = new WebhookRule($endpoint, $this->webhooks->nextRulePosition($endpoint), $action, $match, $params);
        $this->webhooks->saveRule($rule);

        return $rule;
    }

    /**
     * Stores the Bitbucket `pipeline:write` token a run-pipeline rule calls the API with (replacing any earlier one).
     * The token is sealed and is not readable again by this service, a page, a command or a log.
     *
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function setPipelineToken(string $projectName, #[\SensitiveParameter] string $token): void
    {
        $endpoint = $this->bitbucketEndpoint($projectName);
        $token = trim($token);
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            // Never echoes the value: it is a secret even when it is the wrong one.
            throw new ValidationFailed(['token' => 'Enter the access token: printable characters without spaces, at most 2048.']);
        }
        $this->secrets->storeToken($endpoint, $token);
    }

    /**
     * @return bool whether a token was stored
     *
     * @throws NotFound
     */
    public function clearPipelineToken(string $projectName): bool
    {
        $endpoint = $this->bitbucketEndpoint($projectName);
        if (!$endpoint->hasToken()) {
            return false;
        }
        $this->secrets->forgetToken($endpoint);

        return true;
    }

    /**
     * @throws NotFound
     */
    private function bitbucketEndpoint(string $projectName): WebhookEndpoint
    {
        $this->project($projectName);

        return $this->webhooks->endpoint($projectName, WebhookSource::Bitbucket)
            ?? throw new NotFound(\sprintf('%s has no Bitbucket webhook yet: create its secret first.', $projectName));
    }

    /**
     * @throws NotFound
     */
    public function removeRule(string $projectName, int $ruleId): void
    {
        $rule = $this->webhooks->findRule($ruleId);
        if (null === $rule || $rule->endpoint()->project()->name() !== $projectName) {
            throw new NotFound(\sprintf('Rule %d does not exist in project %s.', $ruleId, $projectName));
        }
        $this->webhooks->removeRule($rule);
    }

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     *
     * @return array<string, string>
     */
    private function jiraErrors(Project $project, WebhookEndpoint $endpoint, string $action, array $match, array $params): array
    {
        $errors = [];
        if (WebhookRule::ACTION_DEPLOY !== $action) {
            $errors['action'] = 'A Jira rule can only deploy.';
        }
        $status = $match['on_status'] ?? '';
        if ('' === $status || \strlen($status) > 100 || 1 === preg_match('/[\x00-\x1F\x7F]/', $status)) {
            $errors['onStatus'] = 'Enter the Jira status the issue moves into (at most 100 characters).';
        } else {
            foreach ($endpoint->rules() as $rule) {
                if (0 === strcasecmp($rule->match()['on_status'] ?? '', $status)) {
                    $errors['onStatus'] = 'There is already a rule for this status.';
                }
            }
        }

        return $errors + $this->deployErrors($project, $params, false);
    }

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     *
     * @return array<string, string>
     */
    private function bitbucketErrors(Project $project, string $action, array $match, array $params): array
    {
        $errors = [];
        if (1 !== preg_match(self::EVENT_PATTERN, $match['on_event'] ?? '')) {
            $errors['onEvent'] = 'Enter a Bitbucket event key such as pullrequest:approved or repo:push.';
        }
        if (1 !== preg_match(self::REPO_PATTERN, $match['repo'] ?? '')) {
            $errors['repo'] = 'Enter the repository as workspace/slug.';
        }
        if (isset($match['branch']) && 1 !== preg_match(self::GLOB_PATTERN, $match['branch'])) {
            $errors['branch'] = 'Use letters, digits, dot, underscore, dash, slash and the wildcards * and ?.';
        }
        if (isset($match['state']) && 1 !== preg_match(self::STATE_PATTERN, $match['state'])) {
            $errors['state'] = 'Enter a commit status state such as SUCCESSFUL.';
        }
        if (isset($match['comment']) && ('' === $match['comment'] || \strlen($match['comment']) > self::TEXT_MAX || 1 === preg_match('/[\x00-\x1F\x7F]/', $match['comment']))) {
            $errors['comment'] = 'Enter the comment line to look for (one line, at most 200 characters).';
        }

        if (WebhookRule::ACTION_RUN_PIPELINE === $action) {
            if (1 !== preg_match(self::PIPELINE_PATTERN, $params['pattern'] ?? '')) {
                $errors['pattern'] = 'Enter the name of the custom pipeline.';
            }
            if (isset($params['ref']) && null !== BranchName::error($params['ref'])) {
                $errors['ref'] = 'Enter a valid branch name.';
            }

            return $errors;
        }
        if (WebhookRule::ACTION_DEPLOY !== $action) {
            return $errors + ['action' => 'The action is deploy or run-pipeline.'];
        }
        if (!isset($match['branch'])) {
            $errors['branch'] = 'A deploy rule needs the branch glob to deploy.';
        }

        return $errors + $this->deployErrors($project, $params, true, $match);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $match
     *
     * @return array<string, string>
     */
    private function deployErrors(Project $project, array $params, bool $labelAllowed, array $match = []): array
    {
        $errors = [];
        $template = $params['template'] ?? '';
        if (!$labelAllowed && (isset($params['label_prefix']) || isset($params['strip_suffix']))) {
            $errors['labelPrefix'] = 'A Jira rule names its instance after the issue key.';
        }
        if (!\in_array($template, $this->projects->templateNames($project), true)) {
            $errors['template'] = 'Choose one of the project\'s templates.';
        }
        if (isset($params['branch']) && null !== BranchName::error($params['branch'])) {
            $errors['branch'] = 'Enter a valid branch name.';
        }
        if (isset($params['label']) && (!$labelAllowed || 1 !== preg_match(self::LABEL_PATTERN, $params['label']))) {
            $errors['label'] = 'Use lowercase letters, digits and single dashes, at most 40 characters.';
        }
        if (!$labelAllowed) {
            return $errors;
        }
        if (isset($params['label'], $params['label_prefix']) || isset($params['label'], $params['strip_suffix'])) {
            $errors['label'] = 'A fixed label cannot be combined with a label prefix or a suffix to strip.';
        }
        if (isset($params['label_prefix']) && 1 !== preg_match(self::LABEL_PREFIX_PATTERN, $params['label_prefix'])) {
            $errors['labelPrefix'] = 'Use lowercase letters, digits and dashes, at most 20 characters.';
        }
        if (isset($params['strip_suffix'])) {
            if (1 !== preg_match(self::SUFFIX_PATTERN, $params['strip_suffix'])) {
                $errors['stripSuffix'] = 'Enter the literal text to remove from the end of the branch.';
            } elseif (!isset($params['label_prefix'])) {
                $errors['stripSuffix'] = 'A suffix to strip needs a label prefix too.';
            } elseif (!str_ends_with($match['branch'] ?? '', $params['strip_suffix'])) {
                $errors['stripSuffix'] = 'The branch glob must end with the suffix to strip.';
            }
        }

        return $errors;
    }

    private function project(string $name): Project
    {
        return $this->projects->findByName($name) ?? throw NotFound::project($name);
    }
}
