<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\IssuedToken;
use App\Domain\ApiToken\ApiScope;
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

/**
 * FLE-285: `GET /api/v1/instances/{id}/terminal` through the api firewall: scope, project, instance pattern, which panes
 * exist at all, cleaning, caps and the rate limit. tmux is the recording runner with scripted answers.
 */
final class ApiTerminalReadTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('develop', 'full');
            new ProjectRepository($em)->save($project);
            new TemplateRepository($em)->save(new Template($project, 'full'));
            $instance = new Instance($project, 'fle-1', 'full', 'develop', 'admin');
            $instance->changeState(InstanceState::Running);
            new InstanceRepository($em)->createMany([$instance]);
        }
    }

    /**
     * @param list<string> $globs
     * @param list<string> $projects
     */
    private function token(array $globs = ['acme--fle-*'], array $projects = ['acme'], ApiScope $scope = ApiScope::TerminalRead): IssuedToken
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);

        return $issuer->issue('watch', 'test', [$scope], $projects, $globs, [], new \DateTimeImmutable('+1 day'), 'cli');
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    /** Scripts a window `$id` with the sidebar and two shells, and `$capture` as what capture-pane prints. */
    private function window(string $id, string $capture): RecordingCommandRunner
    {
        $panes = "%1\t0\t0\t50\t200\tsidebar\tsh -c fleet:tmux:sidebar\n%2\t52\t0\t70\t200\t\tbash\n%3\t124\t0\t70\t200\t\tbash\n";

        return $this->runner()
            ->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n$id\n"))
            ->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $panes))
            ->expect(['tmux', '-L', 'fleet', 'capture-pane'], new CommandResult(0, $capture));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, ?IssuedToken $token = null): array
    {
        $token ??= $this->token();
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token->secret, 'REMOTE_ADDR' => $this->clientIp, 'HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function captureCalls(): int
    {
        return \count($this->runner()->callsMatching(['tmux', '-L', 'fleet', 'capture-pane']));
    }

    public function testReadsTty2ByDefaultCleanedOfEscapes(): void
    {
        $this->window('acme--fle-1', "\e[32mhello\e[0m  \r\nworld\e]0;title\a\x07\n\n\n");

        $body = $this->get('/api/v1/instances/acme--fle-1/terminal');

        self::assertResponseStatusCodeSame(200);
        self::assertSame("hello\nworld", $body['text']);
        self::assertSame('tty2', $body['pane']);
        self::assertSame(40, $body['lines']);
        self::assertFalse($body['truncated']);
        $call = $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'capture-pane'])[0]->argv;
        self::assertSame(['-S', '-40'], \array_slice($call, -2));
        self::assertContains('%3', $call, 'tty2 is the second shell, never the sidebar');
    }

    public function testTty1IsTheFirstShell(): void
    {
        $this->window('acme--fle-1', 'x');

        $this->get('/api/v1/instances/acme--fle-1/terminal?pane=tty1&lines=5');

        self::assertResponseStatusCodeSame(200);
        $call = $this->runner()->callsMatching(['tmux', '-L', 'fleet', 'capture-pane'])[0]->argv;
        self::assertContains('%2', $call);
        self::assertSame(['-S', '-5'], \array_slice($call, -2));
    }

    public function testOutputIsCappedKeepingTheEnd(): void
    {
        $this->window('acme--fle-1', str_repeat('a', 70000).'END');

        $body = $this->get('/api/v1/instances/acme--fle-1/terminal');

        self::assertSame(65536, \strlen($body['text']));
        self::assertTrue($body['truncated']);
        self::assertStringEndsWith('END', $body['text']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQueries(): iterable
    {
        yield 'sidebar' => ['pane=sidebar'];
        yield 'general' => ['pane=general'];
        yield 'index' => ['pane=0'];
        yield 'tmux target' => ['pane=%251'];
        yield 'zero lines' => ['lines=0'];
        yield 'too many lines' => ['lines=201'];
        yield 'not a number' => ['lines=abc'];
        yield 'array' => ['lines[]=1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQueries')]
    public function testBadPaneOrLinesAreRefusedBeforeTmuxRuns(string $query): void
    {
        $this->window('acme--fle-1', 'x');

        $this->get('/api/v1/instances/acme--fle-1/terminal?'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->captureCalls());
    }

    public function testTheGeneralWindowIsNotAnInstance(): void
    {
        $this->window('general', 'secrets');

        $this->get('/api/v1/instances/general/terminal', $this->token(['*']));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->captureCalls());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonMatchingGlobs(): iterable
    {
        yield 'none listed' => [[]];
        yield 'other pattern' => [['acme--other-*']];
        yield 'other project' => [['demo--*']];
        yield 'prefix only' => [['acme--fle']];
    }

    /**
     * @param list<string> $globs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonMatchingGlobs')]
    public function testAnInstanceOutsideTheTokenPatternsIs403(array $globs): void
    {
        $this->window('acme--fle-1', 'x');

        $body = $this->get('/api/v1/instances/acme--fle-1/terminal', $this->token($globs));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('instance_not_allowed', $body['code']);
        self::assertSame(0, $this->captureCalls());
    }

    public function testGlobEscapeAndClassPatternsCannotBeIssued(): void
    {
        foreach (['acme--fle-\\*', 'acme--fle-[0-9]', '../*', 'acme--fle-1/..'] as $glob) {
            try {
                $this->token([$glob]);
                self::fail('Pattern accepted: '.$glob);
            } catch (\App\Api\Token\InvalidApiTokenRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnotherProjectIs403EvenWithAMatchingPattern(): void
    {
        $this->window('demo--fle-1', 'x');

        $body = $this->get('/api/v1/instances/demo--fle-1/terminal', $this->token(['*--fle-*']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('project_not_allowed', $body['code']);
        self::assertSame(0, $this->captureCalls());
    }

    public function testTheReadScopeDoesNotOpenTheTerminal(): void
    {
        $this->window('acme--fle-1', 'x');

        $body = $this->get('/api/v1/instances/acme--fle-1/terminal', $this->token(['*'], ['*'], ApiScope::Read));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('scope_missing', $body['code']);
    }

    public function testAnUnknownInstanceIs404(): void
    {
        $this->get('/api/v1/instances/acme--ghost/terminal');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingWindowOrPaneIs404(): void
    {
        $this->runner()->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));

        $this->get('/api/v1/instances/acme--fle-1/terminal');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEachReadIsAuditedWithCountsNotText(): void
    {
        $this->window('acme--fle-1', 'TOPSECRETLINE');
        $this->get('/api/v1/instances/acme--fle-1/terminal?lines=7');

        $conn = $this->em()->getConnection();
        $row = $conn->fetchAssociative("SELECT actor, subject FROM audit_entry WHERE action = 'api.terminal.read'");
        self::assertIsArray($row);
        self::assertStringStartsWith('token:', $row['actor']);
        self::assertStringContainsString('acme--fle-1 Tty2 lines=7 bytes=13', $row['subject']);
        self::assertStringNotContainsString('TOPSECRETLINE', $row['subject']);
    }

    public function testTheTerminalHasItsOwnRateLimit(): void
    {
        $token = $this->token();
        $status = 200;
        for ($i = 0; $i < 31 && 200 === $status; ++$i) {
            $this->window('acme--fle-1', 'x');
            $this->get('/api/v1/instances/acme--fle-1/terminal', $token);
            $status = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(429, $status);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }
}
