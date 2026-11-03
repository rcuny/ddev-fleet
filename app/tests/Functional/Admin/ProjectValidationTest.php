<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every validation message the project services return, field by field, through the New project form and the General
 * tab, as a table of inputs and expected messages. They moved here from the Playwright spec (FLE-127), which keeps only
 * what a browser adds (focus, accessibility, overflow). A refused form answers 422, names its message, keeps what was
 * typed and stores nothing.
 */
final class ProjectValidationTest extends AdminTestCase
{
    private const string GIT = 'https://git.example.com/acme/blog.git';
    private const string ONLY_SCHEMES = 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';
    private const string NAME_RULE = 'Use lowercase letters, digits and single dashes only';
    private const string BRANCH_RULE = 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.';
    private const string BRANCH_NAME_INVALID = 'This is not a valid git branch name';
    private const string BRANCH_REFERENCE = 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.';
    private const string PATH_RULE = 'Use a relative path such as web/themes/custom: segments of letters, digits, dot, underscore and dash, each starting with a letter or digit.';
    private const string NO_PATH = 'The repository path is missing or contains characters that are not allowed.';
    private const string PORT_RULE = 'The port must be a number between 1 and 65535.';
    private const string HOST_RULE = 'The host name is not valid.';
    private const string HOSTNAME_RULE = 'A hostname label uses lowercase letters, digits and single dashes only';
    private const string IDENTITY_EMPTY = 'Enter a name, an email or both, or choose another git identity mode.';
    private const string PATTERN_NESTED = 'nested quantifier: a repeated group must not contain a quantifier or an alternation';
    private const string PATTERN_SYNTAX = 'the pattern uses syntax that is not allowed';

    private const string FORM = '[data-testid=project-form]';

    private function makeProject(string $name = 'blog', string $git = self::GIT): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, $git));
    }

    private function stored(string $name): ?Project
    {
        return new ProjectRepository($this->em())->findByName($name);
    }

    private function projectCount(): int
    {
        return \count(new ProjectRepository($this->em())->all());
    }

    /**
     * @param array<string, mixed> $fields form field name (without the "project[...]" wrapper) => value
     *
     * @return array<string, mixed>
     */
    private static function wrap(array $fields): array
    {
        $values = [];
        foreach ($fields as $name => $value) {
            $values['project['.$name.']'] = $value;
        }

        return $values;
    }

    /**
     * Submits the New project form with a valid name and Git URL, `$fields` replacing them or adding to them.
     *
     * @param array<string, mixed> $fields
     */
    private function create(array $fields): Crawler
    {
        $crawler = $this->open('/admin/projects/new');

        return $this->submit($crawler, 'Create project', self::wrap($fields + ['name' => 'blog', 'git' => self::GIT]));
    }

    /**
     * Creates a project from valid defaults and follows the redirect to its edit page.
     *
     * @param array<string, mixed> $fields
     */
    private function createAndFollow(array $fields = [], string $name = 'blog'): Crawler
    {
        $this->create($fields + ['name' => $name]);
        self::assertResponseRedirects('/admin/projects/'.$name, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Project created.', $crawler->filter('[data-testid=flashes]')->text());

        return $crawler;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(Crawler $crawler, array $fields): Crawler
    {
        return $this->submit($crawler, 'Save', self::wrap($fields));
    }

    /**
     * @return array<string, mixed> the values of the form as the page now shows them
     */
    private function shown(Crawler $crawler, string $button = 'Save'): array
    {
        return $crawler->selectButton($button)->form()->getValues();
    }

    /**
     * Asserts a refused create form: 422, every message in the form, no flash, nothing stored, what was typed kept.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $messages
     */
    private function assertRefusedOnCreate(array $fields, array $messages): void
    {
        $crawler = $this->create($fields);

        self::assertResponseStatusCodeSame(422);
        $text = $crawler->filter(self::FORM)->text();
        foreach ($messages as $message) {
            self::assertStringContainsString($message, $text);
        }
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
        self::assertSame(0, $this->projectCount(), 'a refused form creates nothing');

        $shown = $this->shown($crawler, 'Create project');
        foreach (['name', 'git', 'defaultBranch', 'additionalHostnames', 'issueIdRegexp', 'displaySubmoduleBranch'] as $field) {
            if (isset($fields[$field]) && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', (string) $fields[$field])) {
                // The browser drops the newline right after <textarea> and the crawler a trailing one; the text is otherwise as typed.
                self::assertSame(rtrim((string) $fields[$field], "\n"), rtrim(str_replace("\r\n", "\n", (string) $shown['project['.$field.']']), "\n"), $field.' keeps what was typed');
            }
        }
    }

    // --- name

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'name: empty' => ['', 'Enter a name.'];
        yield 'name: uppercase and a space' => ['Bad Name', self::NAME_RULE];
        yield 'name: starts with a dash' => ['-abc', self::NAME_RULE];
        yield 'name: ends with a dash' => ['abc-', self::NAME_RULE];
        yield 'name: an underscore' => ['a_b', self::NAME_RULE];
        yield 'name: a dot' => ['a.b', self::NAME_RULE];
        yield 'name: two dashes in a row' => ['ab--cd', 'The name must not contain two dashes in a row'];
        yield 'name: reserved word' => ['new', 'This name is reserved.'];
        yield 'name: 41 characters' => [str_repeat('a', 41), 'The name must be at most 40 characters.'];
    }

    #[DataProvider('refusedNames')]
    public function testANameIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['name' => $value], [$message]);
    }

    public function testFortyCharactersDigitsAndSingleDashesAreAccepted(): void
    {
        $name = str_pad('a1-b2-', 40, '9');
        self::assertSame(40, \strlen($name));

        $this->createAndFollow([], $name);

        self::assertNotNull($this->stored($name));
    }

    public function testANameThatExistsIsRefusedAndTheFirstProjectIsUntouched(): void
    {
        $this->makeProject();

        $crawler = $this->create(['git' => 'https://git.example.com/other/repo.git']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A project with this name already exists.', $crawler->filter(self::FORM)->text());
        self::assertSame(self::GIT, ($this->stored('blog') ?? self::fail())->git());
        self::assertSame(1, $this->projectCount());
    }

    public function testTheNameIsNotEditableOnAnExistingProject(): void
    {
        $crawler = $this->createAndFollow();

        self::assertCount(0, $crawler->filter('[name="project[name]"]'));
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
    }

    // --- git URL

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedGitUrls(): iterable
    {
        yield 'git URL: empty' => ['', 'Enter the repository URL.'];
        yield 'git URL: longer than 2048 characters' => ['https://git.example.com/'.str_repeat('a', 2030), 'The URL must be at most 2048 characters.'];
        yield 'git URL: a space inside' => ['https://git.example.com/a b.git', 'The URL must be plain ASCII without spaces or control characters.'];
        yield 'git URL: non-ASCII' => ['https://git.example.com/café.git', 'The URL must be plain ASCII without spaces or control characters.'];
        yield 'git URL: starts with a dash' => ['--upload-pack=x', 'The URL must not start with a dash.'];
        yield 'git URL: http' => ['http://git.example.com/a.git', self::ONLY_SCHEMES];
        yield 'git URL: ftp' => ['ftp://git.example.com/a.git', self::ONLY_SCHEMES];
        yield 'git URL: file' => ['file:///srv/repo.git', self::ONLY_SCHEMES];
        yield 'git URL: git://' => ['git://git.example.com/a.git', self::ONLY_SCHEMES];
        yield 'git URL: no scheme and no colon' => ['git.example.com/acme/blog.git', self::ONLY_SCHEMES];
        yield 'git URL: a mistyped scheme' => ['https:/git.example.com/a.git', self::ONLY_SCHEMES];
        yield 'git URL: credentials in https' => ['https://user:token@git.example.com/a.git', 'Do not put credentials in the URL: use the deploy key.'];
        yield 'git URL: a port above 65535' => ['https://git.example.com:70000/a.git', self::PORT_RULE];
        yield 'git URL: port 0' => ['https://git.example.com:0/a.git', self::PORT_RULE];
        yield 'git URL: a port that is not a number' => ['https://git.example.com:abc/a.git', self::PORT_RULE];
        yield 'git URL: a host starting with a dash' => ['https://-bad.example.com/a.git', self::HOST_RULE];
        yield 'git URL: an underscore in the host' => ['https://bad_host.example.com/a.git', self::HOST_RULE];
        yield 'git URL: a numeric top level that is not an address' => ['https://git.example.1234/a.git', self::HOST_RULE];
        yield 'git URL: an address with a part above 255' => ['https://10.0.0.256/a.git', self::HOST_RULE];
        yield 'git URL: an empty host' => ['https:///a.git', self::HOST_RULE];
        yield 'git URL: no repository path' => ['https://git.example.com', self::NO_PATH];
        yield 'git URL: only a slash as path' => ['https://git.example.com/', self::NO_PATH];
        yield 'git URL: a query string' => ['https://git.example.com/a.git?x=1', self::NO_PATH];
        yield 'git URL: a fragment' => ['https://git.example.com/a.git#x', self::NO_PATH];
        yield 'git URL: a backslash' => ['https://git.example.com/a\\b.git', self::NO_PATH];
        yield 'git URL: a bad percent escape' => ['https://git.example.com/a%zz', 'The repository path contains an invalid percent escape.'];
        yield 'git URL: an encoded control character' => ['https://git.example.com/a%0Ab', 'The repository path contains characters that are not allowed.'];
        yield 'git URL: an encoded question mark' => ['https://git.example.com/a%3Fb', 'The repository path contains characters that are not allowed.'];
        yield 'git URL: a dot-dot segment' => ['https://git.example.com/a/../b.git', 'The repository path must not contain "..".'];
        yield 'git URL: an encoded dot-dot segment' => ['https://git.example.com/a/%2e%2e/b.git', 'The repository path must not contain "..".'];
        yield 'git URL: ssh with a password' => ['ssh://user:pw@git.example.com/a.git', 'The ssh user is not valid (no password in the URL; use the deploy key).'];
        yield 'git URL: ssh with two @' => ['ssh://a@b@git.example.com/a.git', 'The URL is not valid.'];
        yield 'git URL: ssh with a bad port' => ['ssh://git@git.example.com:99999/a.git', self::PORT_RULE];
        yield 'git URL: ssh without a path' => ['ssh://git@git.example.com', self::NO_PATH];
        yield 'git URL: scp with a bad user' => ['us$er@git.example.com:acme/a.git', 'The ssh user is not valid.'];
        yield 'git URL: scp with two @' => ['a@b@git.example.com:acme/a.git', 'The URL is not valid.'];
        yield 'git URL: scp with a bad host' => ['git@-bad:acme/a.git', self::HOST_RULE];
        yield 'git URL: scp with a path that starts with a dash' => ['git@git.example.com:-x/a.git', 'The repository path must not start with a dash.'];
        yield 'git URL: scp with an empty path' => ['git@git.example.com:', self::NO_PATH];
    }

    #[DataProvider('refusedGitUrls')]
    public function testAGitUrlIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['git' => $value], [$message]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedGitUrls(): iterable
    {
        yield 'git URL: https' => ['https://git.example.com/acme/blog.git'];
        yield 'git URL: https with a port and a nested path' => ['https://git.example.com:8443/group/sub/blog'];
        yield 'git URL: https with an address' => ['https://192.0.2.10/acme/blog.git'];
        yield 'git URL: ssh with a user and a port' => ['ssh://git@git.example.com:2222/acme/blog.git'];
        yield 'git URL: ssh without a user' => ['ssh://git.example.com/acme/blog.git'];
        yield 'git URL: scp-like' => ['git@git.example.com:acme/blog.git'];
        yield 'git URL: scp-like without a user' => ['git.example.com:acme/blog.git'];
        yield 'git URL: a percent-encoded path' => ['https://git.example.com/%7Euser/blog.git'];
    }

    #[DataProvider('acceptedGitUrls')]
    public function testAGitUrlIsAcceptedAndShownOnTheEditPage(string $value): void
    {
        $crawler = $this->createAndFollow(['git' => $value]);

        self::assertSame($value, $this->shown($crawler)['project[git]']);
        self::assertSame($value, ($this->stored('blog') ?? self::fail())->git());
    }

    public function testSurroundingWhitespaceOfTheGitUrlIsTrimmed(): void
    {
        $crawler = $this->createAndFollow(['git' => '  '.self::GIT."\n"]);

        self::assertSame(self::GIT, $this->shown($crawler)['project[git]']);
    }

    // --- default branch

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedBranches(): iterable
    {
        yield 'branch: longer than 200 characters' => [str_repeat('a', 201), 'The branch name must be at most 200 characters.'];
        yield 'branch: starts with a dash' => ['-main', self::BRANCH_RULE];
        yield 'branch: starts with a slash' => ['/main', self::BRANCH_RULE];
        yield 'branch: a space' => ['my branch', self::BRANCH_RULE];
        yield 'branch: a colon' => ['a:b', self::BRANCH_RULE];
        yield 'branch: two dots' => ['a..b', 'This is not a valid git branch name (no "..", "//", "/.", trailing "/" or ".", or ".lock" ending).'];
        yield 'branch: a double slash' => ['a//b', self::BRANCH_NAME_INVALID];
        yield 'branch: a segment starting with a dot' => ['a/.b', self::BRANCH_NAME_INVALID];
        yield 'branch: a trailing slash' => ['a/', self::BRANCH_NAME_INVALID];
        yield 'branch: a trailing dot' => ['a.', self::BRANCH_NAME_INVALID];
        yield 'branch: a .lock ending' => ['a.lock', self::BRANCH_NAME_INVALID];
        yield 'branch: a .lock segment' => ['a.lock/b', self::BRANCH_NAME_INVALID];
        yield 'branch: HEAD' => ['HEAD', self::BRANCH_REFERENCE];
        yield 'branch: a full reference path' => ['refs/heads/main', self::BRANCH_REFERENCE];
    }

    #[DataProvider('refusedBranches')]
    public function testADefaultBranchIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['defaultBranch' => $value], [$message]);
    }

    public function testABlankBranchIsOptionalANormalOneIsKeptAndABlankOneOnEditClearsIt(): void
    {
        $crawler = $this->createAndFollow(['defaultBranch' => 'release/1.2_x-y']);
        self::assertSame('release/1.2_x-y', $this->shown($crawler)['project[defaultBranch]']);

        $this->save($crawler, ['defaultBranch' => '   ']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertStringContainsString('Project saved.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame('', $this->shown($this->open('/admin/projects/blog'))['project[defaultBranch]']);
        self::assertNull(($this->stored('blog') ?? self::fail())->defaultBranch());
    }

    // --- additional hostnames

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedHostnames(): iterable
    {
        yield 'hostnames: a label with a dot, with its line number' => ["www\nbad.label", 'Hostname 2: A hostname label uses lowercase letters, digits and single dashes only (no dots).'];
        yield 'hostnames: a label with a capital letter' => ['Upper', 'Hostname 1: '.self::HOSTNAME_RULE];
        yield 'hostnames: a label with two dashes' => ['a--b', 'Hostname 1: '.self::HOSTNAME_RULE];
        yield 'hostnames: a label too long for the project name' => [str_repeat('x', 60), 'Hostname 1: This hostname label is too long for this project name'];
        yield 'hostnames: a repeated label' => ["www\nshop\nwww", 'Each hostname can be listed once.'];
        yield 'hostnames: more than fifty labels' => [implode("\n", array_map(static fn (int $i): string => 'h'.$i, range(0, 50))), 'At most 50 additional hostnames are allowed.'];
        yield 'hostnames: a text longer than 10000 characters' => [str_repeat("a\n", 5001), 'The text must be at most 10000 characters.'];
    }

    #[DataProvider('refusedHostnames')]
    public function testAdditionalHostnamesAreRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['additionalHostnames' => $value], [$message]);
    }

    public function testFiftyHostnamesAreAccepted(): void
    {
        $fifty = implode("\n", array_map(static fn (int $i): string => 'h'.$i, range(0, 49)));

        $crawler = $this->createAndFollow(['additionalHostnames' => $fifty]);

        self::assertSame($fifty, str_replace("\r\n", "\n", $this->shown($crawler)['project[additionalHostnames]']));
        self::assertCount(50, ($this->stored('blog') ?? self::fail())->additionalHostnames());
    }

    public function testBlankLinesAndPaddingAreDroppedAndLabelsKeepTheirOrder(): void
    {
        $crawler = $this->createAndFollow(['additionalHostnames' => "\n  www  \n\n\tshop\n"]);
        self::assertSame(['www', 'shop'], ($this->stored('blog') ?? self::fail())->additionalHostnames());
        self::assertSame("www\nshop", str_replace("\r\n", "\n", $this->shown($crawler)['project[additionalHostnames]']));

        $this->save($crawler, ['additionalHostnames' => '']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertSame([], ($this->stored('blog') ?? self::fail())->additionalHostnames());
    }

    // --- git identity

    public function testTheThreeModesAreOfferedAndTheHostDefaultIsPreselectedOnANewProject(): void
    {
        $crawler = $this->open('/admin/projects/new');

        $modes = $crawler->filter('input[name="project[gitBotMode]"]');
        self::assertSame(['inherit', 'disabled', 'override'], $modes->each(static fn (Crawler $m): string => (string) $m->attr('value')));
        $shown = $this->shown($crawler, 'Create project');
        self::assertSame('inherit', $shown['project[gitBotMode]']);
        self::assertSame('', $shown['project[gitBotName]']);
        self::assertSame('', $shown['project[gitBotEmail]']);
    }

    public function testACustomIdentityWithNothingEnteredIsRefused(): void
    {
        $crawler = $this->create(['gitBotMode' => 'override']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::IDENTITY_EMPTY, $crawler->filter(self::FORM)->text());
        self::assertSame('override', $this->shown($crawler, 'Create project')['project[gitBotMode]'], 'Custom identity stays selected');
        self::assertSame(0, $this->projectCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedIdentityNames(): iterable
    {
        yield 'identity name: longer than 100 characters' => [str_repeat('n', 101), 'The name must be at most 100 characters.'];
        yield 'identity name: an angle bracket' => ['Bot <x>', 'The name must not contain control characters, "<" or ">".'];
        yield 'identity name: only spaces and no email' => ['   ', self::IDENTITY_EMPTY];
    }

    #[DataProvider('refusedIdentityNames')]
    public function testAnIdentityNameIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['gitBotMode' => 'override', 'gitBotName' => $value], [$message]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIdentityEmails(): iterable
    {
        yield 'identity email: without an at sign' => ['bot.example.com'];
        yield 'identity email: without a domain dot' => ['bot@localhost'];
        yield 'identity email: with a space' => ['bot @example.com'];
        yield 'identity email: with two at signs' => ['a@b@example.com'];
        yield 'identity email: with a one-letter top level' => ['bot@example.c'];
    }

    #[DataProvider('refusedIdentityEmails')]
    public function testAnIdentityEmailIsRefused(string $value): void
    {
        $crawler = $this->create(['gitBotMode' => 'override', 'gitBotEmail' => $value]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a valid email address.', $crawler->filter(self::FORM)->text());
        self::assertSame($value, $this->shown($crawler, 'Create project')['project[gitBotEmail]']);
        self::assertSame(0, $this->projectCount());
    }

    public function testNameOnlyEmailOnlyAndBothAreEachAcceptedAndReRead(): void
    {
        $crawler = $this->createAndFollow(['gitBotMode' => 'override', 'gitBotName' => 'Acme Bot']);
        $shown = $this->shown($crawler);
        self::assertSame('override', $shown['project[gitBotMode]']);
        self::assertSame('Acme Bot', $shown['project[gitBotName]']);
        self::assertSame('', $shown['project[gitBotEmail]']);

        $this->save($crawler, ['gitBotName' => '', 'gitBotEmail' => 'bot@example.com']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        $shown = $this->shown($this->open('/admin/projects/blog'));
        self::assertSame('', $shown['project[gitBotName]']);
        self::assertSame('bot@example.com', $shown['project[gitBotEmail]']);

        $this->save($this->open('/admin/projects/blog'), ['gitBotName' => 'Zoë Bot', 'gitBotEmail' => 'zoe+bot@git.example.com']);
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(GitBotMode::Override, $project->gitBotMode());
        self::assertSame('Zoë Bot', $project->gitBotName());
        self::assertSame('zoe+bot@git.example.com', $project->gitBotEmail());
    }

    public function testLeavingCustomIdentityForgetsTheNameAndEmailAndJunkTypedUnderAnotherModeIsIgnored(): void
    {
        $crawler = $this->createAndFollow(['gitBotMode' => 'override', 'gitBotName' => 'Acme Bot', 'gitBotEmail' => 'bot@example.com']);

        $this->save($crawler, ['gitBotMode' => 'disabled']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(GitBotMode::Disabled, $project->gitBotMode());
        self::assertNull($project->gitBotName());
        self::assertNull($project->gitBotEmail());

        $this->save($this->open('/admin/projects/blog'), ['gitBotMode' => 'inherit', 'gitBotEmail' => 'not an email', 'gitBotName' => '<<']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertStringContainsString('Project saved.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(GitBotMode::Inherit, $project->gitBotMode());
        self::assertNull($project->gitBotName());
        self::assertNull($project->gitBotEmail());
    }

    // --- issue id pattern

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedPatterns(): iterable
    {
        yield 'pattern: longer than 200 characters' => ['A'.str_repeat('b', 200), 'The pattern must be at most 200 characters.'];
        yield 'pattern: a control character' => ["AB\u{1}C", 'The pattern must not contain control characters.'];
        yield 'pattern: a tilde' => ['AB~C', 'The pattern must not contain "~".'];
        yield 'pattern: an unclosed group' => ['(ABC', 'invalid pattern:'];
        yield 'pattern: an unclosed class' => ['[ABC', 'invalid pattern:'];
        yield 'pattern: a quantifier with nothing to repeat' => ['*ABC', 'invalid pattern:'];
        yield 'pattern: literal quoting' => ['\\QABC\\E', 'literal quoting (\\Q...\\E) is not allowed in the pattern'];
        yield 'pattern: a numbered backreference' => ['(AB)\\1', 'backreferences are not allowed in the pattern'];
        yield 'pattern: a named backreference' => ['(?<n>AB)\\k<n>', 'backreferences are not allowed in the pattern'];
        yield 'pattern: recursion' => ['(a)(?1)', 'the pattern uses syntax that is not allowed (recursion, callouts, conditionals, comments, control verbs)'];
        yield 'pattern: a comment group' => ['AB(?#note)', self::PATTERN_SYNTAX];
        yield 'pattern: a callout' => ['AB(?C1)', self::PATTERN_SYNTAX];
        yield 'pattern: a nested quantifier' => ['(a+)+', self::PATTERN_NESTED];
        yield 'pattern: a repeated alternation' => ['(a|aa)*', self::PATTERN_NESTED];
        yield 'pattern: a counted group with a quantifier inside' => ['(\\d+){2,}', 'nested quantifier'];
        yield 'pattern: a pattern that backtracks too much' => ['\\w*\\w*\\w*\\w*\\d', 'pattern is too expensive'];
    }

    #[DataProvider('refusedPatterns')]
    public function testAnIssueIdPatternIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['issueIdRegexp' => $value], [$message]);
    }

    public function testARefusedPatternOnTheEditPageIsNotSaved(): void
    {
        $crawler = $this->createAndFollow();

        $crawler = $this->save($crawler, ['issueIdRegexp' => '(a+)+']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nested quantifier', $crawler->filter(self::FORM)->text());
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
        self::assertNull(($this->stored('blog') ?? self::fail())->issueIdRegexp());
        self::assertSame('', $this->shown($this->open('/admin/projects/blog'))['project[issueIdRegexp]']);
    }

    public function testValidPatternsAreSavedAndShownAgainAndABlankOneClearsThePattern(): void
    {
        $crawler = $this->createAndFollow(['issueIdRegexp' => '(?:ABC|XYZ)-\d{1,6}']);
        self::assertSame('(?:ABC|XYZ)-\d{1,6}', $this->shown($crawler)['project[issueIdRegexp]']);

        $this->save($crawler, ['issueIdRegexp' => '']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertNull(($this->stored('blog') ?? self::fail())->issueIdRegexp());
    }

    // --- submodule whose branch is shown

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedSubmodulePaths(): iterable
    {
        yield 'submodule: longer than 200 characters' => [str_repeat('a', 201), 'The path must be at most 200 characters.'];
        yield 'submodule: absolute' => ['/web/themes', self::PATH_RULE];
        yield 'submodule: with a trailing slash' => ['web/themes/', self::PATH_RULE];
        yield 'submodule: with a double slash' => ['web//themes', self::PATH_RULE];
        yield 'submodule: with a dot-dot' => ['web/../x', self::PATH_RULE];
        yield 'submodule: starting with a dot' => ['.hidden/x', self::PATH_RULE];
        yield 'submodule: with a space' => ['web/my theme', self::PATH_RULE];
        yield 'submodule: with a backslash' => ['web\\themes', self::PATH_RULE];
    }

    #[DataProvider('refusedSubmodulePaths')]
    public function testASubmodulePathIsRefused(string $value, string $message): void
    {
        $this->assertRefusedOnCreate(['displaySubmoduleBranch' => $value], [$message]);
    }

    public function testANestedSubmodulePathIsSavedReReadAndClearedAgain(): void
    {
        $crawler = $this->createAndFollow(['displaySubmoduleBranch' => 'web/modules/custom/blog_2.x']);
        self::assertSame('web/modules/custom/blog_2.x', $this->shown($crawler)['project[displaySubmoduleBranch]']);

        $this->save($crawler, ['displaySubmoduleBranch' => '']);
        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertNull(($this->stored('blog') ?? self::fail())->displaySubmoduleBranch());
    }

    // --- required and optional fields

    public function testAnEmptyFormShowsTheTwoRequiredFieldMessagesTogetherAndCreatesNothing(): void
    {
        $crawler = $this->create(['name' => '', 'git' => '']);

        self::assertResponseStatusCodeSame(422);
        $text = $crawler->filter(self::FORM)->text();
        self::assertStringContainsString('Enter a name.', $text);
        self::assertStringContainsString('Enter the repository URL.', $text);
        self::assertStringNotContainsString('Enter a branch name.', $text);
        self::assertSame(0, $this->projectCount());
    }

    public function testEveryOptionalFieldCanBeLeftEmpty(): void
    {
        $crawler = $this->createAndFollow();

        $shown = $this->shown($crawler);
        self::assertSame(self::GIT, $shown['project[git]']);
        foreach (['defaultBranch', 'additionalHostnames', 'gitBotName', 'gitBotEmail', 'issueIdRegexp', 'displaySubmoduleBranch'] as $field) {
            self::assertSame('', $shown['project['.$field.']'], $field);
        }
        self::assertSame('inherit', $shown['project[gitBotMode]']);
        self::assertArrayNotHasKey('project[typesense]', $shown, 'the Typesense box is unchecked');
        self::assertFalse(($this->stored('blog') ?? self::fail())->typesense());
    }

    public function testSeveralErrorsAreShownAtOnceEachNextToItsOwnFieldAndEveryTypedValueStays(): void
    {
        $fields = ['name' => 'Bad Name', 'git' => 'ftp://x', 'defaultBranch' => 'a..b', 'additionalHostnames' => 'a.b', 'issueIdRegexp' => '(a+)+', 'displaySubmoduleBranch' => '/x'];

        $crawler = $this->create($fields);

        self::assertResponseStatusCodeSame(422);
        $expected = [
            'name' => self::NAME_RULE,
            'git' => self::ONLY_SCHEMES,
            'defaultBranch' => self::BRANCH_NAME_INVALID,
            'additionalHostnames' => 'Hostname 1: A hostname label',
            'issueIdRegexp' => 'nested quantifier',
            'displaySubmoduleBranch' => 'Use a relative path such as web/themes/custom',
        ];
        foreach ($expected as $field => $message) {
            self::assertStringContainsString($message, $crawler->filter('#project_'.$field.'_errors')->text(), $field);
            self::assertSame('true', $crawler->filter('#project_'.$field)->attr('aria-invalid'), $field);
        }
        $shown = $this->shown($crawler, 'Create project');
        foreach ($fields as $field => $value) {
            self::assertSame($value, $shown['project['.$field.']'], $field);
        }
        self::assertSame(0, $this->projectCount());
    }

    // --- refused edit

    public function testARefusedSaveKeepsWhatWasTypedShowsNoSuccessFlashAndSavesNothing(): void
    {
        $crawler = $this->createAndFollow(['defaultBranch' => 'main', 'additionalHostnames' => 'www']);

        $crawler = $this->save($crawler, ['git' => 'http://x', 'defaultBranch' => 'dev', 'additionalHostnames' => 'bad.label']);

        self::assertResponseStatusCodeSame(422);
        $text = $crawler->filter(self::FORM)->text();
        self::assertStringContainsString(self::ONLY_SCHEMES, $text);
        self::assertStringContainsString('Hostname 1:', $text);
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
        $shown = $this->shown($crawler);
        self::assertSame('http://x', $shown['project[git]']);
        self::assertSame('dev', $shown['project[defaultBranch]']);

        // Nothing was saved, not even the valid field.
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(self::GIT, $project->git());
        self::assertSame('main', $project->defaultBranch());
        self::assertSame(['www'], $project->additionalHostnames());
    }

    public function testAProjectWithoutTemplatesSaysSoNextToTheDefaultTemplate(): void
    {
        $crawler = $this->createAndFollow();

        self::assertSame('', $this->shown($crawler)['project[defaultTemplate]']);
        self::assertStringContainsString('Add a template first, then choose which one is the default.', $crawler->filter(self::FORM)->text());
    }

    // --- long values

    public function testLongRefusedValuesAreAllRefusedTogether(): void
    {
        $this->assertRefusedOnCreate(
            [
                'name' => str_repeat('n', 41),
                'git' => 'https://git.example.com/'.str_repeat('a', 2030),
                'defaultBranch' => str_repeat('b', 201),
                'additionalHostnames' => str_repeat('h', 70),
                'issueIdRegexp' => 'A'.str_repeat('b', 200),
                'displaySubmoduleBranch' => str_repeat('s', 201),
            ],
            [
                'The name must be at most 40 characters.',
                'The URL must be at most 2048 characters.',
                'The branch name must be at most 200 characters.',
                'The pattern must be at most 200 characters.',
                'The path must be at most 200 characters.',
            ],
        );
    }

    public function testAFortyCharacterNameAndATwoHundredCharacterUrlAreStoredAndListed(): void
    {
        $name = str_pad('long-name-', 40, 'p');
        $git = 'https://git.example.com/acme/'.str_repeat('r', 200 - \strlen('https://git.example.com/acme/') - 4).'.git';
        self::assertSame(200, \strlen($git));

        $this->createAndFollow(['git' => $git], $name);

        self::assertSame($git, ($this->stored($name) ?? self::fail())->git());
        self::assertStringContainsString($git, $this->open('/admin/projects')->filter('[data-testid=project-row]')->text());
    }
}
