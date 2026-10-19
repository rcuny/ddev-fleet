<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Secrets\TokenRenderer;
use App\Fleet\Secrets\UnresolvedTokens;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TokenRendererTest extends KernelTestCase
{
    use FleetDatabase;

    private SecretStore $store;
    private TokenRenderer $renderer;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->store = self::getContainer()->get(SecretStore::class);
        $this->renderer = new TokenRenderer($this->store);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testTheKebabCaseTokenResolvesTheSecret(): void
    {
        $this->store->putManaged('blog', 'JIRA_CLAUDE_TOKEN', 'abc123', 'admin');

        $rendering = $this->renderer->render('token=[[jira-claude-token]]', 'blog');

        self::assertSame('token=abc123', $rendering->text);
        self::assertTrue($rendering->isComplete());
        self::assertSame([], $rendering->unresolved);
        self::assertSame(['JIRA_CLAUDE_TOKEN'], $rendering->used);
    }

    public function testAProjectSecretWinsOverAFleetWideOne(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'fleet-value', 'admin');
        $this->store->putManaged('blog', 'API_KEY', 'project-value', 'admin');

        self::assertSame('project-value', $this->renderer->render('[[api-key]]', 'blog')->text);
        self::assertSame('fleet-value', $this->renderer->render('[[api-key]]', 'shop')->text);
        self::assertSame('fleet-value', $this->renderer->render('[[api-key]]', null)->text);
    }

    public function testAProjectSecretIsInvisibleToOtherProjectsAndToFleetOnlyRendering(): void
    {
        $this->store->putManaged('blog', 'ONLY_BLOG', 'blog-value', 'admin');

        $other = $this->renderer->render('[[only-blog]]', 'shop');
        self::assertSame('[[only-blog]]', $other->text);
        self::assertSame(['only-blog'], $other->unresolved);
        self::assertSame(['only-blog'], $this->renderer->render('[[only-blog]]', null)->unresolved);
    }

    public function testFallsBackToTheFleetWideSecretWhenTheProjectHasNone(): void
    {
        $this->store->putManaged(null, 'SHARED_KEY', 'shared', 'admin');

        self::assertSame('shared', $this->renderer->render('[[shared-key]]', 'blog')->text);
    }

    public function testUnknownPlaceholdersAreListedSortedOnceAndLeftInTheText(): void
    {
        $this->store->putManaged(null, 'KNOWN', 'k', 'admin');

        $rendering = $this->renderer->render('[[zeta]] [[known]] [[alpha]] [[zeta]]', null);

        self::assertSame('[[zeta]] k [[alpha]] [[zeta]]', $rendering->text);
        self::assertSame(['alpha', 'zeta'], $rendering->unresolved);
        self::assertFalse($rendering->isComplete());
        self::assertSame(['KNOWN'], $rendering->used);
    }

    public function testStrictRenderingRefusesUnresolvedPlaceholders(): void
    {
        $this->store->putManaged(null, 'KNOWN', 'k', 'admin');

        self::assertSame('k', $this->renderer->renderStrict('[[known]]', null));
        try {
            $this->renderer->renderStrict('[[known]] [[nope]] [[also-nope]]', null);
            self::fail('expected UnresolvedTokens');
        } catch (UnresolvedTokens $e) {
            self::assertSame(['also-nope', 'nope'], $e->tokens);
            self::assertSame('Unresolved placeholder(s): [[also-nope]], [[nope]].', $e->getMessage());
            self::assertStringNotContainsString('k', str_replace('known', '', strtolower($e->getMessage())), 'only names, never values');
        }
    }

    public function testValuesAreInsertedVerbatimWithoutQuotingOrEscaping(): void
    {
        $this->store->putManaged(null, 'RAW', 'a "b" \'c\' $d `e` \\f $1 \\1 ${x}', 'admin');

        // The asset file quotes the placeholder itself; the value is not touched, so nothing doubles.
        self::assertSame('TOKEN="a "b" \'c\' $d `e` \\f $1 \\1 ${x}"', $this->renderer->render('TOKEN="[[raw]]"', null)->text);
    }

    public function testAValueThatLooksLikeAPlaceholderIsNotExpandedAgain(): void
    {
        $this->store->putManaged(null, 'FIRST', 'x-[[second]]-y', 'admin');
        $this->store->putManaged(null, 'SECOND', 'SHOULD-NOT-APPEAR', 'admin');

        $rendering = $this->renderer->render('[[first]]', null);

        self::assertSame('x-[[second]]-y', $rendering->text);
        self::assertTrue($rendering->isComplete(), 'a placeholder inside a value is not a placeholder of the text');
        self::assertSame(['FIRST'], $rendering->used);
    }

    public function testASecretValueMayContainDollarAndBackslashSequences(): void
    {
        $this->store->putManaged(null, 'RE', '$0 \\0 $1', 'admin');

        self::assertSame('<$0 \\0 $1>', $this->renderer->render('<[[re]]>', null)->text);
    }

    public function testBuiltInValuesWinOverSecrets(): void
    {
        $this->store->putManaged(null, 'BRANCH_X', 'secret', 'admin');

        $rendering = $this->renderer->render('[[branch]] [[branch-x]]', null, ['branch' => 'main']);

        self::assertSame('main secret', $rendering->text);
        self::assertSame(['BRANCH_X'], $rendering->used);
    }

    public function testABuiltInWithAnEmptyValueStillCounts(): void
    {
        $rendering = $this->renderer->render('<[[issue-id]]>', null, ['issue-id' => '']);

        self::assertSame('<>', $rendering->text);
        self::assertTrue($rendering->isComplete());
    }

    public function testSecretsAreNotReadForBuiltIns(): void
    {
        // Nothing is stored, the built-in resolves, nothing is reported.
        $rendering = $this->renderer->render('[[instance-fqdn]]', 'blog', ['instance-fqdn' => 'blog--main.fleet.example.com']);

        self::assertSame('blog--main.fleet.example.com', $rendering->text);
        self::assertSame([], $rendering->used);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPlaceholders(): iterable
    {
        yield 'single brackets' => ['[token]'];
        yield 'space inside' => ['[[ token ]]'];
        yield 'uppercase' => ['[[TOKEN]]'];
        yield 'underscore' => ['[[my_token]]'];
        yield 'unclosed' => ['[[token'];
        yield 'one closing bracket' => ['[[token]'];
        yield 'empty' => ['[[]]'];
        yield 'dot' => ['[[a.b]]'];
        yield 'newline inside' => ["[[to\nken]]"];
        yield 'nested opening, never closed' => ['[[[token'];
        yield 'twig' => ['{{ token }}'];
        yield 'shell' => ['${TOKEN}'];
    }

    #[DataProvider('notPlaceholders')]
    public function testTextThatIsNotAPlaceholderIsLeftAlone(string $text): void
    {
        $this->store->putManaged(null, 'TOKEN', 'value', 'admin');

        $rendering = $this->renderer->render($text, null);

        self::assertSame($text, $rendering->text);
        self::assertSame([], $rendering->unresolved);
        self::assertSame([], $rendering->used);
    }

    public function testNestedBracketsResolveTheInnerPlaceholder(): void
    {
        $this->store->putManaged(null, 'TOKEN', 'value', 'admin');

        self::assertSame('[[value', $this->renderer->render('[[[[token]]', null)->text);
        self::assertSame('[value]', $this->renderer->render('[[[token]]]', null)->text);
    }

    public function testATokenThatIsNoValidSecretNameIsUnresolved(): void
    {
        $rendering = $this->renderer->render('[[1abc]] [[-x]] [[x-]] [['.str_repeat('a', 65).']]', null);

        self::assertSame(['-x', '1abc', str_repeat('a', 65), 'x-'], $rendering->unresolved);
    }

    public function testHostSecretsAreNeverSubstituted(): void
    {
        $this->store->putFleetWide('claude_oauth_token', 'host-value', 'setup');
        $this->store->putFleetWide('ovh_application_secret', 'ovh-value', 'setup');

        $rendering = $this->renderer->render('[[claude-oauth-token]] [[ovh-application-secret]]', null);

        self::assertSame('[[claude-oauth-token]] [[ovh-application-secret]]', $rendering->text);
        self::assertSame(['claude-oauth-token', 'ovh-application-secret'], $rendering->unresolved);
        self::assertStringNotContainsString('host-value', $rendering->text);
    }

    public function testManyPlaceholdersAndRepeatsInALargeText(): void
    {
        $this->store->putManaged(null, 'A_KEY', 'AAA', 'admin');
        $this->store->putManaged('blog', 'B_KEY', 'BBB', 'admin');
        $text = str_repeat("a=[[a-key]]\nb=[[b-key]]\n", 500);

        $rendering = $this->renderer->render($text, 'blog');

        self::assertSame(str_repeat("a=AAA\nb=BBB\n", 500), $rendering->text);
        self::assertSame(['A_KEY', 'B_KEY'], $rendering->used);
    }

    public function testATextWithoutPlaceholdersIsReturnedAsIs(): void
    {
        $rendering = $this->renderer->render("plain\ntext with \$VARS and {{ twig }}\n", 'blog');

        self::assertSame("plain\ntext with \$VARS and {{ twig }}\n", $rendering->text);
        self::assertSame([], $rendering->used);
        self::assertSame([], $rendering->unresolved);
        self::assertSame('', $this->renderer->render('', null)->text);
    }

    public function testBinarySafeMultiByteTextSurvives(): void
    {
        $this->store->putManaged(null, 'UTF_KEY', 'pässwörd-日本', 'admin');

        self::assertSame('日本=pässwörd-日本;', $this->renderer->render('日本=[[utf-key]];', null)->text);
    }

    public function testACorruptedSecretFailsLoudlyAndNeverRendersPartially(): void
    {
        $this->store->putManaged(null, 'API_KEY', 'value', 'admin');
        self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection()->executeStatement('UPDATE secret SET ciphertext = zeroblob(40)');
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();

        $this->expectException(SecretOpenFailed::class);

        $this->renderer->render('[[api-key]]', null);
    }
}
