<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Secret;

use App\Domain\Audit\AuditEntryRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;

final class SecretCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string VALUE = 'tok-ABC123-never-printed';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function store(): SecretStore
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(SecretStore::class);
    }

    private function makeProject(string $name = 'blog'): void
    {
        self::getContainer()->get(ProjectService::class)->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function audit(): array
    {
        $this->entityManager()->clear();

        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->entityManager())->latest(50),
        );
    }

    // --- set

    public function testSetReadsTheValueFromStandardInput(): void
    {
        $set = $this->cli('fleet:secret:set', ['name' => 'JIRA_TOKEN'], [self::VALUE."\n"]);

        self::assertSame(0, $set->getStatusCode(), $set->getErrorOutput());
        self::assertStringContainsString('"JIRA_TOKEN" saved', $set->getDisplay());
        self::assertStringNotContainsString(self::VALUE, $set->getDisplay().$set->getErrorOutput());
        self::assertSame(self::VALUE, $this->store()->getManaged(null, 'JIRA_TOKEN'));
        self::assertSame([['cli', 'secret.put', 'fleet/JIRA_TOKEN']], $this->audit());
    }

    public function testSetAsksForTheValueWhenInteractive(): void
    {
        $set = $this->cli('fleet:secret:set', ['name' => 'JIRA_TOKEN'], [self::VALUE], interactive: true);

        self::assertSame(0, $set->getStatusCode(), $set->getErrorOutput());
        self::assertStringContainsString('Value', $set->getDisplay());
        self::assertStringNotContainsString(self::VALUE, $set->getDisplay().$set->getErrorOutput());
        self::assertSame(self::VALUE, $this->store()->getManaged(null, 'JIRA_TOKEN'));
    }

    public function testSetForAProjectAndReplace(): void
    {
        $this->makeProject();

        self::assertSame(0, $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--project' => 'blog'], ['first-value-1'])->getStatusCode());
        $again = $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--project' => 'blog'], ['second-value-2']);

        self::assertSame(0, $again->getStatusCode());
        self::assertStringContainsString('of project "blog"', $again->getDisplay());
        self::assertSame('second-value-2', $this->store()->getManaged('blog', 'API_KEY'));
        self::assertNull($this->store()->getManaged(null, 'API_KEY'));
        self::assertSame('project:blog/API_KEY', $this->audit()[0][2]);
    }

    public function testSetRefusesAValueOnTheCommandLine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cli('fleet:secret:set', ['name' => 'API_KEY', 'value' => self::VALUE]);
    }

    public function testThereIsNoValueOption(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--value' => self::VALUE]);
    }

    /**
     * @return iterable<string, array{string, string, string}> name, value, text expected on stderr
     */
    public static function refused(): iterable
    {
        yield 'lowercase name' => ['api_key', 'abc', '<name>: Use capital letters'];
        yield 'built-in' => ['PROJECT', 'abc', '<name>: This name is taken by the built-in [[project]]'];
        yield 'empty value' => ['API_KEY', '', '<value>: Enter a value.'];
        yield 'quoted value' => ['API_KEY', '"abc"', '<value>: Enter the value without surrounding quotes'];
        yield 'oversized' => ['API_KEY', str_repeat('x', 70000), '<value>: The value must be at most 64 KiB.'];
    }

    #[DataProvider('refused')]
    public function testSetRefusedValuesExit1AndNameTheField(string $name, string $value, string $expected): void
    {
        $set = $this->cli('fleet:secret:set', ['name' => $name], [$value]);

        self::assertSame(1, $set->getStatusCode());
        self::assertStringContainsString($expected, $set->getErrorOutput());
        self::assertSame('', $set->getDisplay());
        self::assertSame([], $this->store()->listManaged(null));
        self::assertSame([], $this->audit());
        if (\strlen($value) < 100 && \strlen($value) > 3) {
            self::assertStringNotContainsString(trim($value, '"'), $set->getErrorOutput());
        }
    }

    public function testSetRefusedAsJsonNeverEchoesTheValue(): void
    {
        $set = $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--json' => true], ['"'.self::VALUE.'"']);

        self::assertSame(1, $set->getStatusCode());
        $document = $this->json($set);
        self::assertSame('Invalid values.', $document['error']);
        self::assertArrayHasKey('<value>', $document['fields']);
        self::assertStringNotContainsString(self::VALUE, $set->getDisplay().$set->getErrorOutput());
    }

    public function testSetForAnUnknownProjectExits3(): void
    {
        $set = $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--project' => 'nope'], ['abc']);

        self::assertSame(3, $set->getStatusCode());
        self::assertStringContainsString('Project "nope" does not exist', $set->getErrorOutput());
    }

    // --- list

    public function testListShowsNamesPlaceholdersAndNeverValues(): void
    {
        $this->cli('fleet:secret:set', ['name' => 'JIRA_CLAUDE_TOKEN'], [self::VALUE]);
        $this->store()->putFleetWide('claude_oauth_token', 'host-claude-token-value', 'setup');

        $list = $this->cli('fleet:secret:list');

        self::assertSame(0, $list->getStatusCode());
        self::assertStringContainsString('JIRA_CLAUDE_TOKEN', $list->getDisplay());
        self::assertStringContainsString('[[jira-claude-token]]', $list->getDisplay());
        self::assertStringContainsString('cli', $list->getDisplay());
        self::assertStringNotContainsString(self::VALUE, $list->getDisplay());
        self::assertStringNotContainsString('claude_oauth_token', $list->getDisplay(), 'the host secrets are not managed here');
    }

    public function testListIsEmptyAtFirst(): void
    {
        self::assertStringContainsString('No fleet-wide secrets', $this->cli('fleet:secret:list')->getDisplay());
        $this->makeProject();
        self::assertStringContainsString('Project "blog" has no secrets', $this->cli('fleet:secret:list', ['--project' => 'blog'])->getDisplay());
    }

    public function testListAsJson(): void
    {
        $this->makeProject();
        $this->cli('fleet:secret:set', ['name' => 'USED_KEY', '--project' => 'blog'], [self::VALUE]);
        $this->cli('fleet:secret:set', ['name' => 'LONELY_KEY', '--project' => 'blog'], ['lonely-value-1']);
        $paths = self::getContainer()->get(FleetPaths::class);
        mkdir($paths->assetsDir('blog'), 0o750, true);
        file_put_contents($paths->assetsDir('blog').'/.env', "A=[[used-key]]\n");

        $list = $this->cli('fleet:secret:list', ['--project' => 'blog', '--format' => 'json']);

        self::assertSame(0, $list->getStatusCode());
        self::assertSame('', $list->getErrorOutput());
        $document = $this->json($list);
        self::assertSame('project', $document['scope']);
        self::assertSame('blog', $document['project']);
        $byName = [];
        foreach ($document['secrets'] as $secret) {
            $byName[$secret['name']] = $secret;
        }
        self::assertSame(['LONELY_KEY', 'USED_KEY'], array_keys($byName));
        self::assertTrue($byName['USED_KEY']['referenced']);
        self::assertFalse($byName['LONELY_KEY']['referenced']);
        self::assertSame('[[used-key]]', $byName['USED_KEY']['placeholder']);
        self::assertSame('cli', $byName['USED_KEY']['updated_by']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $byName['USED_KEY']['updated_at']);
        self::assertSame(['name', 'placeholder', 'updated_by', 'updated_at', 'referenced'], array_keys($byName['USED_KEY']));
        self::assertStringNotContainsString(self::VALUE, $list->getDisplay());

        $table = $this->cli('fleet:secret:list', ['--project' => 'blog']);
        self::assertStringContainsString('NO', $table->getDisplay());
        self::assertStringContainsString('LONELY_KEY: no asset file mentions [[lonely-key]]', $table->getDisplay());
    }

    public function testListFleetJsonHasNoReferencedFlag(): void
    {
        $this->cli('fleet:secret:set', ['name' => 'API_KEY'], ['abc-value-1']);

        $document = $this->json($this->cli('fleet:secret:list', ['--json' => true]));

        self::assertSame('fleet', $document['scope']);
        self::assertNull($document['project']);
        self::assertNull($document['secrets'][0]['referenced']);
    }

    public function testListForAnUnknownProjectExits3(): void
    {
        self::assertSame(3, $this->cli('fleet:secret:list', ['--project' => 'nope'])->getStatusCode());
    }

    // --- unset

    public function testUnsetNeedsConfirmation(): void
    {
        $this->cli('fleet:secret:set', ['name' => 'API_KEY'], ['abc-value-1']);

        $refused = $this->cli('fleet:secret:unset', ['name' => 'API_KEY']);
        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('pass --yes', $refused->getErrorOutput());
        self::assertTrue($this->store()->hasManaged(null, 'API_KEY'));

        $no = $this->cli('fleet:secret:unset', ['name' => 'API_KEY'], ['no'], interactive: true);
        self::assertSame(2, $no->getStatusCode());
        self::assertTrue($this->store()->hasManaged(null, 'API_KEY'));

        $yes = $this->cli('fleet:secret:unset', ['name' => 'API_KEY'], ['yes'], interactive: true);
        self::assertSame(0, $yes->getStatusCode());
        self::assertFalse($this->store()->hasManaged(null, 'API_KEY'));
    }

    public function testUnsetWithYesAndJson(): void
    {
        $this->makeProject();
        $this->cli('fleet:secret:set', ['name' => 'API_KEY', '--project' => 'blog'], ['abc-value-1']);

        $unset = $this->cli('fleet:secret:unset', ['name' => 'API_KEY', '--project' => 'blog', '--yes' => true, '--json' => true]);

        self::assertSame(0, $unset->getStatusCode(), $unset->getErrorOutput());
        self::assertSame(['deleted' => 'API_KEY', 'project' => 'blog'], $this->json($unset));
        self::assertSame([['cli', 'secret.delete', 'project:blog/API_KEY'], ['cli', 'secret.put', 'project:blog/API_KEY']], $this->audit());
    }

    public function testUnsetOfAnUnknownSecretOrProjectExits3(): void
    {
        $this->makeProject();

        self::assertSame(3, $this->cli('fleet:secret:unset', ['name' => 'NOPE', '--yes' => true])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:secret:unset', ['name' => 'lower', '--yes' => true])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:secret:unset', ['name' => 'NOPE', '--project' => 'blog', '--yes' => true])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:secret:unset', ['name' => 'NOPE', '--project' => 'nope', '--yes' => true])->getStatusCode());
        self::assertSame([], $this->audit());
    }
}
