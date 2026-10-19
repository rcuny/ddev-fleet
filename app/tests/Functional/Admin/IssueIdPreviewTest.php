<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Security\FleetUser;
use App\Tests\Support\AdminTestCase;
use App\Twig\Components\IssueIdPreview;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The issue-id pattern tester on the project's General tab: a live component that runs the operator's regular
 * expression through IssueIdPattern, so the page shows exactly what a deploy would extract.
 */
final class IssueIdPreviewTest extends AdminTestCase
{
    use InteractsWithLiveComponents;

    private const string URL = '/_components/IssueIdPreview';

    /**
     * @param array<string, string> $data
     */
    private function preview(array $data = []): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        // The test helper mounts the component in this process, outside any request: it needs a signed-in administrator.
        $this->signIn('fleet-admins');

        return $this->createLiveComponent('IssueIdPreview', $data, $this->client);
    }

    private function signIn(string $group): TokenStorageInterface
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }

    /**
     * @return array{state: string|null, message: string|null, issueId: string|null}
     */
    private function outcome(\Symfony\UX\LiveComponent\Test\TestLiveComponent $component): array
    {
        $crawler = $component->render()->crawler();
        $status = $crawler->filter('[data-testid="issue-id-result"]');
        self::assertCount(1, $status, 'one result region');
        self::assertSame('status', $status->attr('role'));
        self::assertSame('polite', $status->attr('aria-live'));
        $id = $crawler->filter('[data-testid="issue-id-extracted"]');
        $message = $crawler->filter('[data-testid="issue-id-message"]');

        return [
            'state' => $status->attr('data-state'),
            'message' => \count($message) > 0 ? trim($message->text()) : null,
            'issueId' => \count($id) > 0 ? trim($id->text()) : null,
        ];
    }

    public function testMatchShowsTheUppercasedKey(): void
    {
        $out = $this->outcome($this->preview(['pattern' => 'OAKS-[0-9]+', 'label' => 'oaks-1781']));

        self::assertSame('match', $out['state']);
        self::assertSame('OAKS-1781', $out['issueId']);
    }

    public function testTypingIntoTheInputsRerendersTheResult(): void
    {
        $component = $this->preview(['pattern' => 'OAKS-[0-9]+']);
        self::assertSame('empty', $this->outcome($component)['state']);

        $component->set('label', 'oaks-42-fix');
        $out = $this->outcome($component);

        self::assertSame('match', $out['state']);
        self::assertSame('OAKS-42', $out['issueId']);
    }

    public function testBranchIsTheFallback(): void
    {
        $out = $this->outcome($this->preview(['pattern' => '([A-Z]+-[0-9]+)', 'label' => 'review', 'branch' => 'feature/acme-77-search']));

        self::assertSame('match', $out['state']);
        self::assertSame('ACME-77', $out['issueId']);
    }

    public function testLabelWinsOverTheBranch(): void
    {
        $out = $this->outcome($this->preview(['pattern' => '([A-Z]+-[0-9]+)', 'label' => 'acme-1', 'branch' => 'feature/acme-77-search']));

        self::assertSame('ACME-1', $out['issueId']);
    }

    public function testInvalidPatternShowsTheRuleMessage(): void
    {
        $out = $this->outcome($this->preview(['pattern' => '(a+)+$', 'label' => 'aaaa']));

        self::assertSame('error', $out['state']);
        self::assertSame(IssueIdPattern::error('(a+)+$'), $out['message']);
        self::assertNull($out['issueId']);
    }

    public function testUncompilablePatternIsAnErrorNotAServerError(): void
    {
        $out = $this->outcome($this->preview(['pattern' => '(unclosed', 'label' => 'x']));

        self::assertSame('error', $out['state']);
        self::assertStringStartsWith('invalid pattern', (string) $out['message']);
    }

    public function testNoMatchAndEmptyStates(): void
    {
        $none = $this->outcome($this->preview(['pattern' => 'OAKS-[0-9]+', 'label' => 'other', 'branch' => 'main']));
        self::assertSame('none', $none['state']);
        self::assertNull($none['issueId']);

        $noPattern = $this->outcome($this->preview(['label' => 'oaks-1']));
        self::assertSame('empty', $noPattern['state']);

        $noSample = $this->outcome($this->preview(['pattern' => 'OAKS-[0-9]+']));
        self::assertSame('empty', $noSample['state']);
    }

    public function testOverlongSamplesAreRefusedNotRun(): void
    {
        $label = $this->outcome($this->preview(['pattern' => '[a-z]+', 'label' => str_repeat('a', IssueIdPattern::LABEL_MAX + 1)]));
        self::assertSame('error', $label['state']);
        self::assertStringContainsString((string) IssueIdPattern::LABEL_MAX, (string) $label['message']);

        $branch = $this->outcome($this->preview(['pattern' => '[a-z]+', 'branch' => str_repeat('a', BranchName::MAX + 1)]));
        self::assertSame('error', $branch['state']);
        self::assertStringContainsString((string) BranchName::MAX, (string) $branch['message']);

        $pattern = $this->outcome($this->preview(['pattern' => str_repeat('a', 10_000), 'label' => 'a']));
        self::assertSame('error', $pattern['state']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function hostileInputs(): iterable
    {
        yield 'nested quantifier on a long label' => ['(a+)+$', str_repeat('a', 63), ''];
        yield 'valid pattern, pathological branch' => ['([a-z]+-)*[0-9]x', '', str_repeat('a-', 99).'1'];
        yield 'NUL in the pattern' => ["a\0b", 'ab', ''];
        yield 'delimiter in the pattern' => ['a~b', 'a~b', ''];
        yield 'multibyte samples' => ['[a-z]+', "caf\u{e9}-\u{1F600}", "\u{1F600}/x"];
        yield 'backreference' => ['(a)\1', 'aa', ''];
    }

    #[DataProvider('hostileInputs')]
    public function testHostileInputNeverFailsAndAlwaysAnswers(string $pattern, string $label, string $branch): void
    {
        $started = hrtime(true);
        $out = $this->outcome($this->preview(['pattern' => $pattern, 'label' => $label, 'branch' => $branch]));

        self::assertContains($out['state'], ['empty', 'error', 'none', 'match']);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9, 'bounded work');
    }

    public function testNoMessageEchoesTheSubmittedPattern(): void
    {
        $secret = 'SECRET<script>alert(1)</script>(unclosed';
        $component = $this->preview(['pattern' => $secret, 'label' => 'x']);
        $out = $this->outcome($component);

        self::assertSame('error', $out['state']);
        self::assertStringNotContainsString('SECRET', (string) $out['message']);
        // The only place the input comes back is the escaped value of its own field.
        self::assertStringNotContainsString('<script>', $component->response()->getContent() ?: '');
    }

    public function testExtractedKeyIsEscaped(): void
    {
        // The id charset keeps markup out of the result; the response must not carry a raw tag in any case.
        $component = $this->preview(['pattern' => '<b>[a-z]+', 'label' => '<b>abc']);

        self::assertStringNotContainsString('<b>abc', (string) $component->response()->getContent());
    }

    public function testOnlyTheThreeInputsAreWritable(): void
    {
        $writable = [];
        foreach (new \ReflectionClass(IssueIdPreview::class)->getProperties() as $property) {
            foreach ($property->getAttributes(LiveProp::class) as $attribute) {
                if ($attribute->newInstance()->isIdentityWritable()) {
                    $writable[] = $property->getName();
                }
            }
        }
        sort($writable);

        self::assertSame(['branch', 'label', 'pattern'], $writable);
    }

    public function testComponentClassAsksTheVoter(): void
    {
        $attributes = new \ReflectionClass(IssueIdPreview::class)->getAttributes(IsGranted::class);

        self::assertCount(1, $attributes, 'class-level IsGranted, so it stays protected when the blanket rule is relaxed');
    }

    /**
     * The `^/_components` rules are the first layer; the hooks are another one: the component itself refuses a member
     * even where the rules and #[IsGranted] are out of the way.
     */
    public function testComponentRefusesAMemberOnItsOwn(): void
    {
        $storage = $this->signIn('demo');

        $component = $this->container()->get(IssueIdPreview::class);
        self::assertInstanceOf(IssueIdPreview::class, $component);

        foreach (['preMount' => static fn () => $component->checkOnMount([]), 'postHydrate' => static fn () => $component->checkOnHydrate()] as $name => $call) {
            try {
                $call();
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->signIn('fleet-admins');
        self::assertSame([], $component->checkOnMount([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function livePropsOfARenderedComponent(): array
    {
        $component = $this->preview(['pattern' => 'OAKS-[0-9]+', 'label' => 'oaks-1']);
        self::assertSame('match', $this->outcome($component)['state']);
        $props = $this->client->getCrawler()->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertIsString($props);

        return json_decode($props, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function callEndpoint(array $props, string $label = 'oaks-1'): int
    {
        $this->client->catchExceptions(true);
        $this->client->request(
            'POST',
            self::BASE.self::URL,
            ['data' => json_encode(['props' => $props, 'updated' => ['label' => $label]], \JSON_THROW_ON_ERROR)],
            [],
            $this->browserServer() + ['HTTP_ACCEPT' => 'application/vnd.live-component+html'],
        );

        return $this->client->getResponse()->getStatusCode();
    }

    public function testRealEndpointAnswersAdmin200MemberAnd403AnonymousAnd401(): void
    {
        $props = $this->livePropsOfARenderedComponent();

        $this->as('admin');
        self::assertSame(200, $this->callEndpoint($props));
        self::assertStringContainsString('OAKS-1', $this->body());

        $this->as('member');
        self::assertSame(403, $this->callEndpoint($props));

        $this->as('none');
        self::assertSame(401, $this->callEndpoint($props));
    }

    public function testMemberCannotCallTheComponent(): void
    {
        $props = $this->livePropsOfARenderedComponent();
        $this->as('member');

        // Render and re-render after typing (the live endpoint), with and without a changed input.
        self::assertSame(403, $this->callEndpoint($props));
        self::assertSame(403, $this->callEndpoint($props, 'oaks-2'));
        self::assertStringNotContainsString('OAKS-', $this->body());
    }

    public function testEditPageRendersTheComponentPrefilledWithTheStoredPattern(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', issueIdRegexp: 'BLOG-[0-9]+'));

        $crawler = $this->open('/admin/projects/blog');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="issue-id-preview"]');
        self::assertCount(1, $card);
        self::assertSame('BLOG-[0-9]+', $card->filter('input[data-model="debounce(300)|pattern"]')->attr('value'));
        self::assertCount(1, $card->filter('input[data-model="debounce(300)|label"]'));
        self::assertCount(1, $card->filter('input[data-model="debounce(300)|branch"]'));
        self::assertCount(1, $card->filter('[role="status"][aria-live="polite"]'));
        // Every field has a visible label.
        foreach (['pattern', 'label', 'branch'] as $field) {
            $id = $card->filter('input[data-model="debounce(300)|'.$field.'"]')->attr('id');
            self::assertNotSame('', (string) $id);
            self::assertCount(1, $card->filter('label[for="'.$id.'"]'));
        }
    }

    public function testEditPageForAProjectWithoutAPatternStartsEmpty(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));

        $crawler = $this->open('/admin/projects/blog');

        self::assertSame('', (string) $crawler->filter('[data-testid="issue-id-preview"] input[data-model="debounce(300)|pattern"]')->attr('value'));
        self::assertSame('empty', $crawler->filter('[data-testid="issue-id-result"]')->attr('data-state'));
    }
}
