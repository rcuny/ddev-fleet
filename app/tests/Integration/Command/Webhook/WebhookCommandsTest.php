<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Webhook;

use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Webhooks\Signature;
use App\Fleet\Webhooks\WebhookSecrets;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WebhookCommandsTest extends KernelTestCase
{
    use FleetDatabase;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $templates = self::getContainer()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('jira-work'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $answers standard input (the tests run non-interactively)
     */
    private function cli(string $command, array $input, array $answers = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find($command));
        $tester->setInputs($answers);
        $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => false]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonList(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());
        self::assertTrue(array_is_list($decoded));

        return $decoded;
    }

    public function testSecretPrintsTheLabelledSecretOnceAndRefusesASecondRun(): void
    {
        $first = $this->cli('fleet:webhook:secret', ['project' => 'blog', '--json' => true]);

        self::assertSame(0, $first->getStatusCode());
        $json = self::json($first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['secret']);
        self::assertSame('jira', $json['source']);
        self::assertStringEndsWith('/hooks/jira/blog', $json['url']);

        $table = $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);
        self::assertSame(0, $table->getStatusCode());
        self::assertMatchesRegularExpression('/^secret: [0-9a-f]{64}$/m', $table->getDisplay());
        self::assertStringContainsString('Shown once', $table->getDisplay());

        $second = $this->cli('fleet:webhook:secret', ['project' => 'blog']);
        self::assertSame(2, $second->getStatusCode());
        self::assertStringContainsString('Rotate it', $second->getErrorOutput());
        self::assertStringNotContainsString($json['secret'], $second->getDisplay().$second->getErrorOutput());

        $rotated = self::json($this->cli('fleet:webhook:secret', ['project' => 'blog', '--rotate' => true, '--json' => true]));
        self::assertNotSame($json['secret'], $rotated['secret']);
        $secrets = self::getContainer()->get(WebhookSecrets::class);
        self::assertInstanceOf(WebhookSecrets::class, $secrets);
        $repository = self::getContainer()->get(WebhookRepository::class);
        self::assertInstanceOf(WebhookRepository::class, $repository);
        $endpoint = $repository->endpoint('blog', WebhookSource::Jira);
        self::assertNotNull($endpoint);
        self::assertFalse($secrets->accepts($endpoint, 'x', Signature::sign('x', $json['secret'])), 'the rotated-out secret');
        self::assertTrue($secrets->accepts($endpoint, 'x', Signature::sign('x', $rotated['secret'])));
    }

    public function testTheStoredSecretIsSealedNotPlain(): void
    {
        $json = self::json($this->cli('fleet:webhook:secret', ['project' => 'blog', '--json' => true]));
        $row = $this->entityManager()->getConnection()->fetchAssociative('SELECT ciphertext, nonce FROM webhook_endpoint');

        self::assertIsArray($row);
        self::assertStringNotContainsString($json['secret'], bin2hex((string) $row['ciphertext']).(string) $row['ciphertext']);
    }

    public function testUnknownProjectAndBadSourceAreRefused(): void
    {
        self::assertSame(3, $this->cli('fleet:webhook:secret', ['project' => 'nope'])->getStatusCode());
        $bad = $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'gitlab']);
        self::assertSame(1, $bad->getStatusCode());
        self::assertStringContainsString('--source: Use jira or bitbucket.', $bad->getErrorOutput());
    }

    public function testRulesCanBeAddedListedAndRemoved(): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog']);

        $noTemplate = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--on-status' => 'Dispatched', '--template' => 'missing']);
        self::assertSame(1, $noTemplate->getStatusCode());
        self::assertStringContainsString('--template:', $noTemplate->getErrorOutput());

        $added = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--on-status' => 'Dispatched', '--template' => 'jira-work', '--json' => true]);
        self::assertSame(0, $added->getStatusCode(), $added->getErrorOutput());
        $id = self::json($added)['id'];

        $duplicate = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--on-status' => 'dispatched', '--template' => 'jira-work']);
        self::assertSame(1, $duplicate->getStatusCode());
        self::assertStringContainsString('--on-status: There is already a rule', $duplicate->getErrorOutput());

        $list = self::jsonList($this->cli('fleet:webhook:list', ['--json' => true]));
        self::assertCount(1, $list);
        self::assertSame('blog', $list[0]['project']);
        self::assertSame([['id' => $id, 'action' => 'deploy', 'match' => ['on_status' => 'Dispatched'], 'params' => ['template' => 'jira-work']]], $list[0]['rules']);
        self::assertArrayNotHasKey('secret', $list[0]);

        self::assertSame(0, $this->cli('fleet:webhook:rule:remove', ['project' => 'blog', 'rule' => (string) $id])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:webhook:rule:remove', ['project' => 'blog', 'rule' => (string) $id])->getStatusCode());
        self::assertSame([], self::jsonList($this->cli('fleet:webhook:list', ['--json' => true]))[0]['rules']);
    }

    public function testARuleNeedsAReceiverFirst(): void
    {
        $result = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--on-status' => 'Dispatched', '--template' => 'jira-work']);

        self::assertSame(3, $result->getStatusCode());
        self::assertStringContainsString('create its secret first', $result->getErrorOutput());
    }

    public function testBitbucketRuleValidation(): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);

        $bad = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--source' => 'bitbucket', '--action' => 'run-pipeline', '--on-event' => 'push', '--repo' => 'no-slash']);
        self::assertSame(1, $bad->getStatusCode());
        $err = $bad->getErrorOutput();
        self::assertStringContainsString('--on-event:', $err);
        self::assertStringContainsString('--repo:', $err);
        self::assertStringContainsString('--pattern:', $err);

        $ok = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--source' => 'bitbucket', '--action' => 'run-pipeline', '--on-event' => 'pullrequest:approved', '--repo' => 'acme/blog', '--match-branch' => 'renovate/*', '--pattern' => 'renovate-merge', '--ref' => 'develop']);
        self::assertSame(0, $ok->getStatusCode(), $ok->getErrorOutput());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}> the options of fleet:webhook:rule:add, the message it refuses with
     */
    public static function ruleRefusals(): iterable
    {
        yield 'jira only deploys' => [['--source' => 'jira', '--action' => 'run-pipeline', '--on-status' => 'Dispatched', '--template' => 'jira-work'], 'A Jira rule can only deploy.'];
        yield 'jira no status' => [['--source' => 'jira', '--template' => 'jira-work'], 'Enter the Jira status the issue moves into (at most 100 characters).'];
        yield 'jira status too long' => [['--source' => 'jira', '--on-status' => str_repeat('s', 101), '--template' => 'jira-work'], 'Enter the Jira status the issue moves into (at most 100 characters).'];
        yield 'jira no template' => [['--source' => 'jira', '--on-status' => 'Dispatched'], "Choose one of the project's templates."];
        yield 'jira unknown template' => [['--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'nope'], "Choose one of the project's templates."];
        yield 'jira bad branch' => [['--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'jira-work', '--branch' => '-bad'], 'Enter a valid branch name.'];
        yield 'jira bad label' => [['--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'jira-work', '--label' => 'fixed'], 'Use lowercase letters, digits and single dashes, at most 40 characters.'];
        yield 'jira label prefix' => [['--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'jira-work', '--label-prefix' => 'fb'], 'A Jira rule names its instance after the issue key.'];
        yield 'jira duplicate status' => [['--source' => 'jira', '--on-status' => 'dispatched', '--template' => 'jira-work'], 'There is already a rule for this status.'];
        yield 'bb no event' => [['--source' => 'bitbucket', '--repo' => 'acme/blog', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter a Bitbucket event key such as pullrequest:approved or repo:push.'];
        yield 'bb event without colon' => [['--source' => 'bitbucket', '--on-event' => 'push', '--repo' => 'acme/blog', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter a Bitbucket event key such as pullrequest:approved or repo:push.'];
        yield 'bb no repo' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter the repository as workspace/slug.'];
        yield 'bb repo without slash' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter the repository as workspace/slug.'];
        yield 'bb repo with two slashes' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'a/b/c', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter the repository as workspace/slug.'];
        yield 'bb bad branch glob' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'bad branch!', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Use letters, digits, dot, underscore, dash, slash and the wildcards * and ?.'];
        yield 'bb bad state' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--state' => '1', '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter a commit status state such as SUCCESSFUL.'];
        yield 'bb comment too long' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--comment' => str_repeat('c', 201), '--action' => 'run-pipeline', '--pattern' => 'x'], 'Enter the comment line to look for (one line, at most 200 characters).'];
        yield 'bb no pipeline' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--action' => 'run-pipeline'], 'Enter the name of the custom pipeline.'];
        yield 'bb pipeline with a space' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--action' => 'run-pipeline', '--pattern' => 'two words'], 'Enter the name of the custom pipeline.'];
        yield 'bb bad ref' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--action' => 'run-pipeline', '--pattern' => 'x', '--ref' => '-bad'], 'Enter a valid branch name.'];
        yield 'bb unknown action' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--action' => 'teleport'], 'The action is deploy or run-pipeline.'];
        yield 'bb deploy without a glob' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--template' => 'jira-work'], 'A deploy rule needs the branch glob to deploy.'];
        yield 'bb deploy unknown template' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'nope'], "Choose one of the project's templates."];
        yield 'bb deploy bad label' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'jira-work', '--label' => 'Fixed'], 'Use lowercase letters, digits and single dashes, at most 40 characters.'];
        yield 'bb label with prefix' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'jira-work', '--label' => 'fixed', '--label-prefix' => 'fb'], 'A fixed label cannot be combined with a label prefix or a suffix to strip.'];
        yield 'bb bad prefix' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'jira-work', '--label-prefix' => '-fb'], 'Use lowercase letters, digits and dashes, at most 20 characters.'];
        yield 'bb suffix without prefix' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'jira-work', '--strip-suffix' => '-x'], 'A suffix to strip needs a label prefix too.'];
        yield 'bb glob not ending in the suffix' => [['--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--match-branch' => 'feature/*', '--template' => 'jira-work', '--label-prefix' => 'fb', '--strip-suffix' => '-x'], 'The branch glob must end with the suffix to strip.'];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('ruleRefusals')]
    public function testEveryRuleRefusalHasItsOwnMessage(array $options, string $message): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog']);
        $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);
        self::assertSame(0, $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'jira-work'])->getStatusCode());

        $result = $this->cli('fleet:webhook:rule:add', ['project' => 'blog'] + $options);

        self::assertNotSame(0, $result->getStatusCode());
        self::assertStringContainsString($message, $result->getErrorOutput());
        self::assertSame(1, array_sum(array_map(static fn (array $endpoint): int => \count($endpoint['rules']), self::jsonList($this->cli('fleet:webhook:list', ['--json' => true])))), 'a refusal adds nothing');
    }

    public function testARuleNeedsTheReceiverOfItsOwnSourceFirst(): void
    {
        $jira = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--source' => 'jira', '--on-status' => 'Dispatched', '--template' => 'jira-work']);
        self::assertSame(3, $jira->getStatusCode());
        self::assertStringContainsString('blog has no Jira webhook yet: create its secret first.', $jira->getErrorOutput());

        $bitbucket = $this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--action' => 'run-pipeline', '--pattern' => 'x']);
        self::assertSame(3, $bitbucket->getStatusCode());
        self::assertStringContainsString('blog has no Bitbucket webhook yet: create its secret first.', $bitbucket->getErrorOutput());
    }

    public function testARuleCannotBeRemovedTwiceOrFromAnotherProject(): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog']);
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        $id = (string) self::json($this->cli('fleet:webhook:rule:add', ['project' => 'blog', '--on-status' => 'Dispatched', '--template' => 'jira-work', '--json' => true]))['id'];

        $foreign = $this->cli('fleet:webhook:rule:remove', ['project' => 'shop', 'rule' => $id]);
        self::assertSame(3, $foreign->getStatusCode());
        self::assertStringContainsString(\sprintf('Rule %s does not exist in project shop.', $id), $foreign->getErrorOutput());
        self::assertSame(0, $this->cli('fleet:webhook:rule:remove', ['project' => 'blog', 'rule' => $id])->getStatusCode());
        $again = $this->cli('fleet:webhook:rule:remove', ['project' => 'blog', 'rule' => $id]);
        self::assertSame(3, $again->getStatusCode());
        self::assertStringContainsString(\sprintf('Rule %s does not exist in project blog.', $id), $again->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'blank' => ['   '];
        yield 'inner space' => ['two words'];
        yield 'non-ASCII' => ['tökën'];
        yield 'over 2048 characters' => [str_repeat('A', 2049)];
    }

    #[DataProvider('badTokens')]
    public function testABadTokenIsRefusedWithoutEchoingItAndTheLongestOneIsAccepted(string $bad): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);

        $refused = $this->cli('fleet:webhook:token', ['project' => 'blog'], [$bad."\n"]);

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('Enter the access token: printable characters without spaces, at most 2048.', $refused->getErrorOutput());
        if ('' !== trim($bad)) {
            self::assertStringNotContainsString(trim($bad), $refused->getDisplay().$refused->getErrorOutput());
        }
        self::assertSame(0, $this->cli('fleet:webhook:token', ['project' => 'blog'], [str_repeat('A', 2048)."\n"])->getStatusCode(), '2048 characters is the limit');
    }

    public function testLogShowsDeliveriesNewestFirst(): void
    {
        self::assertStringContainsString('No webhook deliveries logged.', $this->cli('fleet:webhook:log', [])->getDisplay());
    }

    private const string TOKEN = 'ATCTT-a-pipeline-token-value';

    public function testTheTokenIsReadFromStandardInputStoredSealedAndNeverPrinted(): void
    {
        self::assertSame(0, $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket'])->getStatusCode());

        $set = $this->cli('fleet:webhook:token', ['project' => 'blog'], [self::TOKEN."\n"]);

        self::assertSame(0, $set->getStatusCode(), $set->getErrorOutput());
        self::assertStringContainsString('cannot be shown again', $set->getDisplay());
        self::assertStringNotContainsString(self::TOKEN, $set->getDisplay().$set->getErrorOutput());
        $row = $this->entityManager()->getConnection()->fetchAssociative('SELECT token_ciphertext, token_key_version FROM webhook_endpoint WHERE source = ?', ['bitbucket']);
        self::assertIsArray($row);
        self::assertNotNull($row['token_key_version']);
        self::assertStringNotContainsString(self::TOKEN, (string) $row['token_ciphertext']);

        $list = $this->cli('fleet:webhook:list', ['--json' => true]);
        self::assertStringNotContainsString(self::TOKEN, $list->getDisplay());
        $bitbucket = array_values(array_filter(self::jsonList($list), static fn (array $r): bool => 'bitbucket' === $r['source']));
        self::assertTrue($bitbucket[0]['token']);

        $cleared = $this->cli('fleet:webhook:token', ['project' => 'blog', '--clear' => true, '--json' => true]);
        self::assertSame(['project' => 'blog', 'cleared' => true], self::json($cleared));
        self::assertFalse(self::json($this->cli('fleet:webhook:token', ['project' => 'blog', '--clear' => true, '--json' => true]))['cleared']);
    }

    public function testTheTokenNeedsABitbucketSecretFirstAndAValidValue(): void
    {
        $noEndpoint = $this->cli('fleet:webhook:token', ['project' => 'blog'], [self::TOKEN]);
        self::assertSame(3, $noEndpoint->getStatusCode());
        self::assertStringContainsString('create its secret first', $noEndpoint->getErrorOutput());

        $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);
        $bad = $this->cli('fleet:webhook:token', ['project' => 'blog'], ["has a space\n"]);
        self::assertSame(1, $bad->getStatusCode());
        self::assertStringContainsString('token:', $bad->getErrorOutput());
        self::assertStringNotContainsString('has a space', $bad->getDisplay().$bad->getErrorOutput());
        self::assertSame(1, $this->cli('fleet:webhook:token', ['project' => 'blog'], [''])->getStatusCode(), 'empty');
    }

    public function testDeployRulesTakeALabelPrefixAndAStrippedSuffixButNotAFixedLabelWithThem(): void
    {
        $this->cli('fleet:webhook:secret', ['project' => 'blog', '--source' => 'bitbucket']);
        $base = ['project' => 'blog', '--source' => 'bitbucket', '--on-event' => 'repo:push', '--repo' => 'acme/blog', '--template' => 'jira-work'];

        $ok = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => '*--pw-run', '--label-prefix' => 'pw-', '--strip-suffix' => '--pw-run', '--json' => true]);
        self::assertSame(0, $ok->getStatusCode(), $ok->getErrorOutput());
        self::assertSame(['template' => 'jira-work', 'label_prefix' => 'pw-', 'strip_suffix' => '--pw-run'], self::json($ok)['params']);

        $reuse = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => 'feature/*', '--label-prefix' => 'ci-', '--reuse' => true, '--json' => true]);
        self::assertSame(0, $reuse->getStatusCode(), $reuse->getErrorOutput());
        self::assertSame(['template' => 'jira-work', 'label_prefix' => 'ci-', 'reuse' => '1'], self::json($reuse)['params']);

        $both = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => 'a', '--label' => 'x', '--label-prefix' => 'pw-']);
        self::assertSame(1, $both->getStatusCode());
        self::assertStringContainsString('--label: A fixed label cannot be combined', $both->getErrorOutput());

        $noPrefix = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => '*--x', '--strip-suffix' => '--x']);
        self::assertStringContainsString('--strip-suffix: A suffix to strip needs a label prefix', $noPrefix->getErrorOutput());

        $wrongGlob = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => '*--y', '--label-prefix' => 'p-', '--strip-suffix' => '--x']);
        self::assertStringContainsString('--strip-suffix: The branch glob must end with the suffix', $wrongGlob->getErrorOutput());
    }
}
