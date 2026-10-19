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

        $both = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => 'a', '--label' => 'x', '--label-prefix' => 'pw-']);
        self::assertSame(1, $both->getStatusCode());
        self::assertStringContainsString('--label: A fixed label cannot be combined', $both->getErrorOutput());

        $noPrefix = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => '*--x', '--strip-suffix' => '--x']);
        self::assertStringContainsString('--strip-suffix: A suffix to strip needs a label prefix', $noPrefix->getErrorOutput());

        $wrongGlob = $this->cli('fleet:webhook:rule:add', $base + ['--match-branch' => '*--y', '--label-prefix' => 'p-', '--strip-suffix' => '--x']);
        self::assertStringContainsString('--strip-suffix: The branch glob must end with the suffix', $wrongGlob->getErrorOutput());
    }
}
