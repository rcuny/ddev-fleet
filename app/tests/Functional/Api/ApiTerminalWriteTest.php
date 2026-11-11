<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\IssuedToken;
use App\Domain\ApiCall\ApiCall;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * FLE-286: `POST /api/v1/instances/{id}/terminal`. Every gate has a test that proves it stops the call before a single
 * `send-keys` runs, and the happy path proves the evidence row and the audit entry. tmux is the recording runner.
 */
final class ApiTerminalWriteTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('develop', 'full');
            $project->changeAllowApiTerminal('acme' === $name);
            new ProjectRepository($em)->save($project);
            new TemplateRepository($em)->save(new Template($project, 'full'));
            $instance = new Instance($project, 'fle-1', 'full', 'develop', 'admin');
            $instance->changeState(InstanceState::Running);
            new InstanceRepository($em)->createMany([$instance]);
        }
    }

    /**
     * @param list<string>   $globs
     * @param list<string>   $projects
     * @param list<ApiScope> $scopes
     */
    private function token(array $globs = ['acme--fle-*'], array $projects = ['acme'], array $scopes = [ApiScope::TerminalWrite]): IssuedToken
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);

        return $issuer->issue('nudge', 'test', $scopes, $projects, $globs, [$this->clientIp], new \DateTimeImmutable('+1 day'), 'cli');
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    /**
     * Scripts the window `$id` (sidebar + two shells, as list-panes prints them) and the foreground command tmux reports,
     * once for the check and once for the re-check just before the keys.
     *
     * @param list<string> $foreground
     */
    private function window(string $id, array $foreground = ['claude', 'claude'], string $panes = "%1\t0\t0\t50\t200\tsidebar\tsh -c fleet:tmux:sidebar\n%2\t52\t0\t70\t200\t\tbash\n%3\t124\t0\t70\t200\t\tbash\n"): void
    {
        $runner = $this->runner()
            ->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n$id\n"))
            ->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $panes));
        foreach ($foreground as $command) {
            $runner->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "$id\t$command\t0\n"));
        }
    }

    /**
     * @param array<string, mixed>|string $body
     *
     * @return array<string, mixed>
     */
    private function send(string $uri, array|string $body, ?IssuedToken $token = null): array
    {
        $token ??= $this->token();
        $this->client->request('POST', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none', 'CONTENT_TYPE' => 'application/json'], content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function sendCalls(): int
    {
        return \count($this->runner()->callsMatching(['tmux', '-L', 'fleet', 'send-keys']));
    }

    /**
     * @return list<ApiCall>
     */
    private function evidence(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(ApiCall::class)->findAll();
    }

    /**
     * @return list<array{string, string}>
     */
    private function audit(): array
    {
        return array_map(static fn ($e): array => [$e->action(), $e->subject()], new AuditEntryRepository($this->em())->latest(50));
    }

    public function testTypesTheTextIntoTheAgentPaneAndRecordsItFirst(): void
    {
        $this->window('acme--fle-1', ['claude', 'claude', 'claude']);

        $body = $this->send('/api/v1/instances/acme--fle-1/terminal', ['pane' => 'tty2', 'text' => 'Please rebase and push.', 'enter' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('claude', $body['foreground']);
        self::assertSame(hash('sha256', 'Please rebase and push.'), $body['sha256']);
        $sends = $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'send-keys']);
        self::assertCount(2, $sends);
        self::assertSame(['send-keys', '-t', '%3', '-l', '--', 'Please rebase and push.'], \array_slice($sends[0]->argv, 3));
        self::assertSame(['send-keys', '-t', '%3', 'Enter'], \array_slice($sends[1]->argv, 3));

        $rows = $this->evidence();
        self::assertCount(1, $rows);
        self::assertSame('Please rebase and push.', $rows[0]->text());
        self::assertSame(ApiCall::SENT, $rows[0]->outcome());
        self::assertSame('claude', $rows[0]->foreground());
        self::assertSame($this->clientIp, $rows[0]->clientIp());
        self::assertContains('api.terminal.write', array_column($this->audit(), 0));
        $subject = array_values(array_filter($this->audit(), static fn (array $a): bool => 'api.terminal.write' === $a[0]))[0][1];
        self::assertStringContainsString('foreground=claude', $subject);
        self::assertStringNotContainsString('rebase', $subject, 'the text lives in api_call, not in the audit line');
    }

    public function testEnterIsOnlySentWhenAsked(): void
    {
        $this->window('acme--fle-1');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->sendCalls());
    }

    public function testTty1IsTheFirstShell(): void
    {
        $this->window('acme--fle-1');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['pane' => 'tty1', 'text' => 'hello']);

        self::assertContains('%2', $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'send-keys'])[0]->argv);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foregrounds(): iterable
    {
        foreach (['bash', 'sh', 'zsh', 'dash', 'fish', '-bash', 'node', 'python3', 'vim', 'ssh', 'sudo', 'su', 'tmux', 'claude-evil', 'Claude', ''] as $command) {
            yield "foreground '$command'" => [$command];
        }
    }

    #[DataProvider('foregrounds')]
    public function testRefusedWhenTheForegroundIsNotTheAgent(string $command): void
    {
        $this->window('acme--fle-1', [$command]);

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'rm -rf ~']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->sendCalls());
        self::assertSame([], $this->evidence());
        self::assertContains('api.denied', array_column($this->audit(), 0));
    }

    public function testRefusedWhenThePaneStopsRunningTheAgentBetweenTheCheckAndTheSend(): void
    {
        // The race: claude at the check, a shell at the re-check. Nothing is typed and the row says failed.
        $this->window('acme--fle-1', ['claude', 'bash']);

        $body = $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('pane_changed', $body['code'] ?? $body['type'] ?? null);
        self::assertSame(0, $this->sendCalls());
        $rows = $this->evidence();
        self::assertCount(1, $rows);
        self::assertSame(ApiCall::FAILED, $rows[0]->outcome());
    }

    public function testRefusedWhenThePaneMovedToAnotherWindowBetweenTheCheckAndTheSend(): void
    {
        $runner = $this->runner()
            ->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--fle-1\n"))
            ->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, "%1\t0\t0\t50\t200\tsidebar\tx\n%2\t52\t0\t70\t200\t\tbash\n%3\t124\t0\t70\t200\t\tbash\n"))
            ->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "acme--fle-1\tclaude\t0\n"))
            ->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "general\tclaude\t0\n"));
        unset($runner);

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->sendCalls());
    }

    public function testAPaneThatIsNotInTheInstancesOwnWindowIsNotAddressable(): void
    {
        $this->runner()
            ->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--fle-1\n"))
            ->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, "%1\t0\t0\t50\t200\tsidebar\tx\n%3\t124\t0\t70\t200\t\tbash\n%2\t52\t0\t70\t200\t\tbash\n"))
            ->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "general\tclaude\t0\n"));

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->sendCalls());
    }

    public function testASplitWindowWithoutTagsHasNoAddressablePane(): void
    {
        $this->window('acme--fle-1', ['claude', 'claude'], "%1\t0\t0\t50\t200\tsidebar\tx\n%2\t52\t0\t70\t100\t\tbash\n%3\t124\t0\t70\t100\t\tbash\n%4\t124\t40\t70\t100\t\tbash\n");

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->sendCalls());
    }

    public function testATaggedPaneIsFoundEvenWhenTheWindowWasSplit(): void
    {
        $this->window('acme--fle-1', ['claude', 'claude'], "%1\t0\t0\t50\t200\tsidebar\tx\n%2\t52\t0\t70\t100\ttty1\tbash\n%5\t124\t0\t70\t40\t\tbash\n%3\t124\t40\t70\t100\ttty2\tbash\n");

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(200);
        self::assertContains('%3', $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'send-keys'])[0]->argv);
    }

    public function testNothingIsSentWhenTheEvidenceCannotBeWritten(): void
    {
        $this->window('acme--fle-1');
        $this->em()->getConnection()->executeStatement('DROP TABLE api_call');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, $this->sendCalls());
    }

    public function testNothingIsSentWhenTheAuditEntryCannotBeWritten(): void
    {
        $this->window('acme--fle-1');
        $token = $this->token();
        $this->em()->getConnection()->executeStatement('DROP TABLE audit_entry');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $token);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, $this->sendCalls());
    }

    public function testRefusedWhenTheProjectHasNotOptedIn(): void
    {
        $this->window('demo--fle-1');

        $this->send('/api/v1/instances/demo--fle-1/terminal', ['text' => 'hello'], $this->token(['demo--*'], ['demo']));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->sendCalls());
        self::assertSame([], $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'display-message']));
    }

    public function testRefusedWhenTheInstanceIsNotRunning(): void
    {
        $this->window('acme--fle-1');
        $this->em()->getConnection()->executeStatement("UPDATE instance SET state = 'stopped' WHERE id = 'acme--fle-1'");

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->sendCalls());
    }

    public function testATerminalReadTokenCannotWrite(): void
    {
        $this->window('acme--fle-1');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $this->token(scopes: [ApiScope::TerminalRead]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->sendCalls());
    }

    public function testMissingForeignAndUnmatchedInstancesAnswerIdentically(): void
    {
        $this->window('acme--fle-1');
        $answers = [];

        $answers['missing'] = [$this->send('/api/v1/instances/acme--nope/terminal', ['text' => 'hello']), $this->client->getResponse()->getStatusCode()];
        $answers['other project'] = [$this->send('/api/v1/instances/demo--fle-1/terminal', ['text' => 'hello']), $this->client->getResponse()->getStatusCode()];
        $answers['outside patterns'] = [$this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $this->token(['acme--other-*'])), $this->client->getResponse()->getStatusCode()];
        $answers['token for another project'] = [$this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $this->token(['acme--fle-*'], ['demo'])), $this->client->getResponse()->getStatusCode()];

        $reference = $answers['missing'][0];
        unset($reference['request_id']);
        foreach ($answers as $label => [$body, $status]) {
            self::assertSame(404, $status, $label);
            unset($body['request_id']);
            // The whole problem body, not selected fields: any extra key or changed value would tell the cases apart.
            self::assertSame($reference, $body, $label);
            self::assertStringNotContainsString('acme', json_encode($body, \JSON_THROW_ON_ERROR), $label);
        }
        self::assertSame(0, $this->sendCalls());
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function tamperedTokens(): iterable
    {
        yield 'no ip allow-list' => ['ip_allow', []];
        yield 'deploy scope added' => ['scopes', ['terminal:write', 'deploy']];
        yield 'lifecycle scope added' => ['scopes', ['terminal:write', 'lifecycle']];
        yield 'no instance patterns' => ['instance_globs', []];
        yield 'lifetime of 30 days' => ['expires_at', ['+30 days']];
    }

    /**
     * @param list<mixed> $value
     */
    #[DataProvider('tamperedTokens')]
    public function testATokenEditedInTheDatabaseAfterIssueIsRefused(string $column, array $value): void
    {
        $this->window('acme--fle-1');
        $token = $this->token();
        $stored = '+30 days' === ($value[0] ?? null) ? new \DateTimeImmutable('+30 days')->format('Y-m-d H:i:s') : json_encode($value, \JSON_THROW_ON_ERROR);
        $this->em()->getConnection()->executeStatement(\sprintf('UPDATE api_token SET %s = :v', $column), ['v' => $stored]);
        $this->em()->clear();

        $body = $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('token_not_permitted', $body['code'] ?? null);
        self::assertSame(0, $this->sendCalls());
        self::assertSame([], $this->evidence());
        self::assertNotEmpty(array_filter($this->audit(), static fn (array $a): bool => 'api.denied' === $a[0] && str_contains($a[1], 'token_policy_')));
    }

    public function testSeveralTokensCannotFloodOneInstance(): void
    {
        $tokens = [$this->token(), $this->token(), $this->token()];
        $accepted = 0;
        $limited = 0;
        // Each token sends once per call (so the per-token limits never trip); the per-instance limit is 10 a minute.
        for ($i = 0; $i < 12; ++$i) {
            $this->window('acme--fle-1');
            $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'x'.$i], $this->token());
            200 === $this->client->getResponse()->getStatusCode() ? ++$accepted : ++$limited;
        }

        self::assertSame(10, $accepted);
        self::assertSame(2, $limited);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        unset($tokens);
    }

    public function testRefusalsPastTheQuotaGetNoAuditRows(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 30; ++$i) {
            $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => '/bad'], $token);
            self::assertResponseStatusCodeSame(422);
        }

        $denials = array_filter($this->audit(), static fn (array $a): bool => 'api.denied' === $a[0] && str_contains($a[1], 'invalid_text'));
        self::assertCount(20, $denials);
    }

    public function testTokenLikeSecretsAreMaskedInTheStoredTextButTheHashIsOfTheOriginal(): void
    {
        $this->window('acme--fle-1');
        $text = 'use ghp_abcdefghijklmnopqrstuvwxyz0123456789AB to push';

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => $text]);

        self::assertResponseStatusCodeSame(200);
        $rows = $this->evidence();
        self::assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz0123456789AB', $rows[0]->text());
        self::assertSame(hash('sha256', $text), $rows[0]->sha256());
    }

    public function testEnterIsNotSentWhenTheAgentLeavesAfterTheText(): void
    {
        $this->window('acme--fle-1', ['claude', 'claude', 'bash']);

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello', 'enter' => true]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->sendCalls(), 'the text was typed, Enter was not');
        self::assertSame(ApiCall::FAILED, $this->evidence()[0]->outcome());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileInstanceIds(): iterable
    {
        yield 'general window' => ['general'];
        yield 'glob star' => ['acme--*'];
        yield 'glob question' => ['acme--fle-?'];
        yield 'glob class' => ['acme--fle-[1]'];
        yield 'escaped glob' => ['acme--fle-%5C*'];
        yield 'tmux target' => ['%251'];
        yield 'colon' => ['fleet:general'];
        yield 'dot dot' => ['..'];
    }

    #[DataProvider('hostileInstanceIds')]
    public function testGlobsAndWindowNamesAreNotInstanceIds(string $id): void
    {
        $this->window('general');

        $this->send('/api/v1/instances/'.$id.'/terminal', ['text' => 'hello'], $this->token(['*']));

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 403, 404, 405]);
        self::assertSame(0, $this->sendCalls());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function badBodies(): iterable
    {
        yield 'sidebar pane' => [['pane' => 'sidebar', 'text' => 'x']];
        yield 'general pane' => [['pane' => 'general', 'text' => 'x']];
        yield 'numeric pane' => [['pane' => 0, 'text' => 'x']];
        yield 'tmux target pane' => [['pane' => '%1', 'text' => 'x']];
        yield 'no text' => [['pane' => 'tty2']];
        yield 'array text' => [['text' => ['a']]];
        yield 'newline' => [['text' => "a\nb"]];
        yield 'escape' => [['text' => "\e[31m"]];
        yield 'bash mode' => [['text' => '!ls']];
        yield 'enter not bool' => [['text' => 'x', 'enter' => 'yes']];
        yield 'too long' => [['text' => str_repeat('a', 5000)]];
        yield 'not json' => ['{nope'];
        yield 'json list' => ['["text"]'];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('badBodies')]
    public function testBadBodiesAreRefusedBeforeTmuxRuns(array|string $body): void
    {
        $this->window('acme--fle-1');

        $this->send('/api/v1/instances/acme--fle-1/terminal', $body);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame(0, $this->sendCalls());
        self::assertSame([], $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'display-message']));
        self::assertSame([], $this->evidence());
    }

    public function testTheSecondCallWithinTwoSecondsIsRateLimited(): void
    {
        $this->window('acme--fle-1');
        $this->window('acme--fle-1');
        $token = $this->token();

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'one'], $token);
        self::assertResponseStatusCodeSame(200);
        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'two'], $token);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertSame(1, $this->sendCalls());
    }

    public function testATokenWithoutAnInstancePatternCannotWrite(): void
    {
        $this->window('acme--fle-1');

        $this->send('/api/v1/instances/acme--fle-1/terminal', ['text' => 'hello'], $this->token([]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->sendCalls());
    }
}
