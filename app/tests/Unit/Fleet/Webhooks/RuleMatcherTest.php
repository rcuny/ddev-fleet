<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Webhooks;

use App\Domain\Project\Project;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Webhooks\PayloadInvalid;
use App\Fleet\Webhooks\RuleMatcher;
use App\Fleet\Webhooks\TargetUnresolvable;
use PHPUnit\Framework\TestCase;

final class RuleMatcherTest extends TestCase
{
    private function project(?string $issuePattern = null): Project
    {
        $project = new Project('blog', 'https://git.example.com/acme/blog.git');
        $project->changeIssueIdRegexp($issuePattern);
        $project->changeDefaults('develop', null);

        return $project;
    }

    private function endpoint(Project $project, WebhookSource $source): WebhookEndpoint
    {
        return new WebhookEndpoint($project, $source, 'c', 'n', 2, new \DateTimeImmutable());
    }

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     */
    private function rule(WebhookEndpoint $endpoint, string $action, array $match, array $params): WebhookRule
    {
        return new WebhookRule($endpoint, 1, $action, $match, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jira(string $key = 'FLE-66', string $from = 'To Do', string $to = 'Dispatched'): array
    {
        return [
            'webhookEvent' => 'jira:issue_updated',
            'issue' => ['key' => $key],
            'changelog' => ['items' => [['field' => 'summary', 'toString' => 'x'], ['field' => 'status', 'fromString' => $from, 'toString' => $to]]],
        ];
    }

    public function testJiraTransitionIntoTheRuleStatusMatches(): void
    {
        $project = $this->project();
        $endpoint = $this->endpoint($project, WebhookSource::Jira);
        $rule = $this->rule($endpoint, 'deploy', ['on_status' => 'dispatched'], ['template' => 'jira-work']);

        $outcome = new RuleMatcher()->match(WebhookSource::Jira, $project, [], self::jira(), [$rule]);

        self::assertSame($rule, $outcome->rule);
        self::assertSame('fle-66', $outcome->label);
        self::assertSame('develop', $outcome->branch);
        self::assertSame('FLE-66', $outcome->subject);
        self::assertSame('To Do -> Dispatched', $outcome->change);
        self::assertSame('deploy fle-66 (template jira-work, branch develop)', $outcome->decision);
    }

    public function testTheRuleBranchWinsOverTheProjectDefault(): void
    {
        $project = $this->project();
        $rule = $this->rule($this->endpoint($project, WebhookSource::Jira), 'deploy', ['on_status' => 'Dispatched'], ['template' => 't', 'branch' => 'release/1']);

        $outcome = new RuleMatcher()->match(WebhookSource::Jira, $project, [], self::jira(), [$rule]);

        self::assertSame('release/1', $outcome->branch);
    }

    public function testAStatusThatIsNotTheRuleStatusIsIgnored(): void
    {
        $project = $this->project();
        $rule = $this->rule($this->endpoint($project, WebhookSource::Jira), 'deploy', ['on_status' => 'Dispatched'], ['template' => 't']);

        $outcome = new RuleMatcher()->match(WebhookSource::Jira, $project, [], self::jira(to: 'Done'), [$rule]);

        self::assertNull($outcome->rule);
        self::assertSame('no rule for this status', $outcome->ignoredReason);
    }

    public function testAnEditWithoutAStatusChangeIsIgnored(): void
    {
        $project = $this->project();
        $payload = self::jira();
        $payload['changelog'] = ['items' => [['field' => 'summary', 'toString' => 'x']]];

        $outcome = new RuleMatcher()->match(WebhookSource::Jira, $project, [], $payload, [$this->rule($this->endpoint($project, WebhookSource::Jira), 'deploy', ['on_status' => 'Dispatched'], ['template' => 't'])]);

        self::assertNull($outcome->rule);
        self::assertSame('no status change', $outcome->ignoredReason);
    }

    public function testAnotherEventIsIgnored(): void
    {
        $payload = self::jira();
        $payload['webhookEvent'] = 'comment_created';

        $outcome = new RuleMatcher()->match(WebhookSource::Jira, $this->project(), [], $payload, []);

        self::assertSame('event is not an issue update', $outcome->ignoredReason);
    }

    public function testAnIssueKeyOutsideTheProjectPatternIsIgnored(): void
    {
        $project = $this->project('FLE-[0-9]+');
        $rule = $this->rule($this->endpoint($project, WebhookSource::Jira), 'deploy', ['on_status' => 'Dispatched'], ['template' => 't']);
        $matcher = new RuleMatcher();

        self::assertNull($matcher->match(WebhookSource::Jira, $project, [], self::jira('OTHER-1'), [$rule])->rule);
        self::assertNotNull($matcher->match(WebhookSource::Jira, $project, [], self::jira('FLE-7'), [$rule])->rule);
    }

    public function testAnIssueUpdateWithoutAKeyIsInvalid(): void
    {
        $payload = self::jira();
        unset($payload['issue']);

        $this->expectException(PayloadInvalid::class);
        new RuleMatcher()->match(WebhookSource::Jira, $this->project(), [], $payload, []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bitbucket(string $branch = 'renovate/lodash', string $repo = 'acme/blog'): array
    {
        return [
            'repository' => ['full_name' => $repo],
            'pullrequest' => ['source' => ['branch' => ['name' => $branch]]],
            'comment' => ['content' => ['raw' => "thanks\n /merge \nbye"]],
        ];
    }

    public function testBitbucketRuleCombinesEventRepoBranchGlobAndCommentLine(): void
    {
        $project = $this->project();
        $rule = $this->rule($this->endpoint($project, WebhookSource::Bitbucket), 'run-pipeline', [
            'on_event' => 'pullrequest:comment_created', 'repo' => 'ACME/blog', 'branch' => 'renovate/*', 'comment' => '/MERGE',
        ], ['pattern' => 'renovate-merge', 'ref' => 'develop']);
        $matcher = new RuleMatcher();
        $headers = ['x-event-key' => 'pullrequest:comment_created'];

        $outcome = $matcher->match(WebhookSource::Bitbucket, $project, $headers, self::bitbucket(), [$rule]);
        self::assertSame($rule, $outcome->rule);
        self::assertSame('run pipeline renovate-merge on develop (acme/blog)', $outcome->decision);

        self::assertNull($matcher->match(WebhookSource::Bitbucket, $project, $headers, self::bitbucket('feature/x'), [$rule])->rule, 'branch glob');
        self::assertNull($matcher->match(WebhookSource::Bitbucket, $project, $headers, self::bitbucket(repo: 'acme/other'), [$rule])->rule, 'repository');
        self::assertNull($matcher->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'pullrequest:approved'], self::bitbucket(), [$rule])->rule, 'event');
    }

    public function testBitbucketDeployDerivesTheLabelFromTheBranch(): void
    {
        $project = $this->project();
        $rule = $this->rule($this->endpoint($project, WebhookSource::Bitbucket), 'deploy', ['on_event' => 'repo:push', 'repo' => 'acme/blog', 'branch' => 'feature/*'], ['template' => 'work']);
        $payload = ['repository' => ['full_name' => 'acme/blog'], 'push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'feature/New_Thing']]]]];

        $outcome = new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], $payload, [$rule]);

        self::assertSame('feature-new-thing', $outcome->label);
        self::assertSame('deploy feature-new-thing (template work, branch feature/New_Thing)', $outcome->decision);
    }

    public function testBitbucketWithoutAnEventKeyIsInvalid(): void
    {
        $this->expectException(PayloadInvalid::class);
        new RuleMatcher()->match(WebhookSource::Bitbucket, $this->project(), [], self::bitbucket(), []);
    }

    public function testLabelsAreSlugs(): void
    {
        self::assertSame('fle-66', RuleMatcher::label('FLE-66'));
        self::assertSame('a-b', RuleMatcher::label('--a//b--'));
        self::assertLessThanOrEqual(40, \strlen(RuleMatcher::label(str_repeat('ab-', 40))));
    }

    /**
     * @param list<array<string, mixed>|null> $news the `new` side of each change (null = a deleted branch)
     *
     * @return array<string, mixed>
     */
    private static function push(array $news): array
    {
        return ['repository' => ['full_name' => 'acme/blog'], 'push' => ['changes' => array_map(static fn (?array $new): array => ['new' => $new], $news)]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function branch(string $name): array
    {
        return ['type' => 'branch', 'name' => $name];
    }

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     */
    private function pushRule(Project $project, array $params, array $match = ['branch' => 'feature/*']): WebhookRule
    {
        return $this->rule($this->endpoint($project, WebhookSource::Bitbucket), 'deploy', ['on_event' => 'repo:push', 'repo' => 'acme/blog'] + $match, ['template' => 'work'] + $params);
    }

    public function testAPushDeploysEveryCreatedOrUpdatedBranchAndSkipsDeletionsAndTags(): void
    {
        $project = $this->project();
        $rule = $this->pushRule($project, [], ['branch' => '*']);
        $payload = self::push([self::branch('feature/a'), null, ['type' => 'tag', 'name' => 'v1'], self::branch('feature/b'), self::branch('feature/a')]);

        $outcome = new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], $payload, [$rule]);

        self::assertSame(['feature-a', 'feature-b'], array_map(static fn ($t): string => $t->label, $outcome->targets));
        self::assertSame(['feature/a', 'feature/b'], array_map(static fn ($t): string => $t->branch, $outcome->targets));
        self::assertFalse($outcome->targets[0]->replace);
    }

    public function testAPushThatOnlyDeletesOrTagsIsIgnored(): void
    {
        $project = $this->project();
        $rule = $this->pushRule($project, [], ['branch' => '*']);

        $outcome = new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], self::push([null, ['type' => 'tag', 'name' => 'v1']]), [$rule]);

        self::assertNull($outcome->rule);
        self::assertSame('no branch was created or updated', $outcome->ignoredReason);
    }

    public function testAFixedLabelReplacesAndAPrefixWithAStrippedSuffixNeverOverwrites(): void
    {
        $project = $this->project();
        $fixed = $this->pushRule($project, ['label' => 'pw-regression'], ['branch' => 'develop']);
        $derived = $this->pushRule($project, ['label_prefix' => 'pw-', 'strip_suffix' => '--pw-run'], ['branch' => '*--pw-run']);
        $payload = self::push([self::branch('develop'), self::branch('feature/ABC-1898-x--pw-run')]);

        $outcome = new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], $payload, [$fixed, $derived]);

        self::assertCount(2, $outcome->targets);
        self::assertSame('pw-regression', $outcome->targets[0]->label);
        self::assertTrue($outcome->targets[0]->replace);
        self::assertSame('pw-feature-abc-1898-x', $outcome->targets[1]->label);
        self::assertFalse($outcome->targets[1]->replace);
    }

    public function testADerivedLabelThatLandsOnAFixedLabelIsSkippedWithItsReason(): void
    {
        $project = $this->project();
        $fixed = $this->pushRule($project, ['label' => 'pw-regression'], ['branch' => 'develop']);
        $derived = $this->pushRule($project, ['label_prefix' => 'pw-'], ['branch' => 'regression']);
        $matcher = new RuleMatcher();
        $headers = ['x-event-key' => 'repo:push'];

        $outcome = $matcher->match(WebhookSource::Bitbucket, $project, $headers, self::push([self::branch('regression'), self::branch('develop')]), [$fixed, $derived]);
        self::assertSame(['pw-regression'], array_map(static fn ($t): string => $t->label, $outcome->targets));
        self::assertSame([['subject' => 'regression', 'reason' => 'collides with a fixed-label rule']], $outcome->skipped);

        $only = $matcher->match(WebhookSource::Bitbucket, $project, $headers, self::push([self::branch('regression')]), [$fixed, $derived]);
        self::assertNull($only->rule);
        self::assertSame('collides with a fixed-label rule', $only->ignoredReason);
    }

    public function testALongBranchIsFittedTo63CharactersWithAHashAndStaysStable(): void
    {
        $project = $this->project();
        $rule = $this->pushRule($project, [], ['branch' => '*']);
        $branch = 'feature/'.str_repeat('very-long-name-', 8);
        $matcher = new RuleMatcher();
        $match = static fn (string $b) => $matcher->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], self::push([self::branch($b)]), [$rule])->targets[0]->label;

        $label = $match($branch);

        self::assertLessThanOrEqual(63, \strlen('blog--'.$label) + 4, 'room for a -999 suffix');
        self::assertMatchesRegularExpression('/-[0-9a-f]{6}$/', $label);
        self::assertSame($label, $match($branch), 'the same branch always lands on the same id');
        self::assertNotSame($label, $match($branch.'x'));
    }

    public function testTheAliasPrefixOfAdditionalHostnamesShortensTheLabel(): void
    {
        $project = $this->project();
        $project->changeHostnames(['adminer-with-a-long-name']);
        $rule = $this->pushRule($project, ['label' => str_repeat('a', 40)], ['branch' => '*']);

        $label = new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], self::push([self::branch('x')]), [$rule])->targets[0]->label;

        self::assertLessThanOrEqual(63, \strlen('adminer-with-a-long-name-blog--'.$label));
    }

    public function testABranchThatSlugifiesToNothingIsUnresolvable(): void
    {
        $project = $this->project();
        $rule = $this->pushRule($project, [], ['branch' => '*']);

        $this->expectException(TargetUnresolvable::class);
        new RuleMatcher()->match(WebhookSource::Bitbucket, $project, ['x-event-key' => 'repo:push'], self::push([self::branch('///')]), [$rule]);
    }
}
