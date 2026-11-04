<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The deploy form over plain HTTP: its defaults, every validation message under its field, the id numbering and what
 * a valid form creates. (The browser suite keeps only the project switch and the phone layout).
 */
final class InstanceDeployFormTest extends AdminTestCase
{
    private const string BRANCH_CHARS = 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.';
    private const string BRANCH_GIT = 'This is not a valid git branch name (no "..", "//", "/.", trailing "/" or ".", or ".lock" ending).';
    private const string BRANCH_REF = 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.';

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $projects = new ProjectRepository($em);
        $templates = new TemplateRepository($em);

        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('main', 'default');
            $projects->save($project);
            $templates->save(new Template($project, 'default'));
        }
        $acme = $projects->findByName('acme');
        self::assertNotNull($acme);
        $templates->save(new Template($acme, 'alt'));

        // No default branch or template.
        $bare = new Project('bare', 'https://git.example.com/bare/blog.git');
        $projects->save($bare);
        $templates->save(new Template($bare, 'only'));

        // An additional host name that makes long labels collide with the 63 characters of a DNS label.
        $alias = new Project('alias', 'https://git.example.com/alias/blog.git');
        $alias->changeDefaults('main', 'default');
        $alias->changeHostnames([str_repeat('h', 30)]);
        $projects->save($alias);
        $templates->save(new Template($alias, 'default'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function deploy(array $values, string $path = '/instances/new'): Crawler
    {
        return $this->submit($this->open($path), 'Deploy', $values);
    }

    /**
     * A POST the form could not send (a value that is not among its choices).
     *
     * @param array<string, mixed> $fields
     */
    private function rawDeploy(array $fields): Crawler
    {
        $token = $this->open('/instances/new')->filter('input[name="deploy[_token]"]')->attr('value');

        return $this->client->request('POST', self::BASE.'/instances/new', ['deploy' => $fields + ['count' => '1', '_token' => $token]], [], $this->browserServer());
    }

    private function formText(Crawler $crawler): string
    {
        return $crawler->filter('[data-testid=deploy-form]')->text();
    }

    /**
     * @return list<Job>
     */
    private function jobs(): array
    {
        /** @var list<Job> $jobs */
        $jobs = $this->em()->createQuery(\sprintf('SELECT j FROM %s j ORDER BY j.id', Job::class))->getResult();

        return $jobs;
    }

    /**
     * @return list<string>
     */
    private function instanceIds(): array
    {
        return new InstanceRepository($this->em())->ids();
    }

    public function testFormShowsEveryFieldWithItsDefaultAndHelp(): void
    {
        $crawler = $this->open('/instances/new?project=acme');

        self::assertResponseStatusCodeSame(200);
        $form = $crawler->filter('[data-testid=deploy-form]');
        self::assertSame('Deploy', trim($crawler->filter('h1')->text()));
        self::assertSame('acme', $form->filter('select[name="deploy[project]"] option[selected]')->attr('value'));
        self::assertSame(['acme', 'alias', 'bare', 'demo'], $form->filter('select[name="deploy[project]"] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame(['Project default', 'alt', 'default'], $form->filter('select[name="deploy[template]"] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame('', $crawler->selectButton('Deploy')->form()->getValues()['deploy[template]']);
        $branch = $form->filter('input[name="deploy[branch]"]');
        self::assertSame('', (string) $branch->attr('value'));
        self::assertSame('main', $branch->attr('placeholder'));
        $label = $form->filter('input[name="deploy[label]"]');
        self::assertSame('', (string) $label->attr('value'));
        self::assertSame('40', $label->attr('maxlength'));
        $count = $form->filter('input[name="deploy[count]"]');
        self::assertSame('1', $count->attr('value'));
        self::assertSame('20', $count->attr('max'));
        self::assertCount(0, $form->filter('input[name="deploy[parallel]"][checked]'));
        $text = $form->text();
        foreach ([
            'Leave blank to use the project default branch.',
            'Part of the instance id. Leave blank to derive it from the branch.',
            '1 to 20 instances; more than one get numbered suffixes.',
            'By default several instances are deployed one after the other.',
        ] as $help) {
            self::assertStringContainsString($help, $text);
        }
        $cancel = $crawler->selectLink('Cancel');
        self::assertSame('/instances', parse_url((string) $cancel->attr('href'), \PHP_URL_PATH));
    }

    public function testAnUnknownProjectFallsBackToTheFirstOne(): void
    {
        $crawler = $this->open('/instances/new?project=no-such-project');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('acme', $crawler->filter('select[name="deploy[project]"] option[selected]')->attr('value'));
    }

    public function testTheTemplateChoicesBelongToTheChosenProject(): void
    {
        $crawler = $this->open('/instances/new?project=demo');

        self::assertSame(['Project default', 'default'], $crawler->filter('select[name="deploy[template]"] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame('demo', $crawler->filter('select[name="deploy[project]"] option[selected]')->attr('value'));
    }

    public function testEveryFieldOfAValidFormReachesTheInstanceAndTheJob(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[template]' => 'alt', 'deploy[branch]' => 'release/1.0-x', 'deploy[label]' => '  My Label_x!  ', 'deploy[count]' => 1]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['acme--my-label-x'], $this->instanceIds());
        $instance = new InstanceRepository($this->em())->find('acme--my-label-x');
        self::assertInstanceOf(Instance::class, $instance);
        self::assertSame('alt', $instance->templateName());
        self::assertSame('release/1.0-x', $instance->branch());
        [$job] = $this->jobs();
        self::assertSame('deploy', $job->type());
        self::assertSame('acme--my-label-x', $job->instanceId());
        self::assertSame('/jobs/'.$job->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testABlankLabelIsDerivedFromTheBranchAndABlankBranchFromTheProjectDefault(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[branch]' => 'Feature/Zz-1']);
        self::assertResponseStatusCodeSame(303);

        // No label and no branch: the id comes from the project's default branch.
        $this->deploy(['deploy[project]' => 'acme']);
        self::assertResponseStatusCodeSame(303);

        self::assertSame(['acme--feature-zz-1', 'acme--main'], $this->instanceIds());
        $main = new InstanceRepository($this->em())->find('acme--main');
        self::assertInstanceOf(Instance::class, $main);
        self::assertSame('main', $main->branch());
        self::assertSame('default', $main->templateName());
    }

    public function testACountAboveOneNumbersTheInstancesAndTheSameLabelAgainGetsASuffix(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'nn', 'deploy[count]' => 2]);
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('2 jobs queued.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame(['acme--nn-1', 'acme--nn-2'], $this->instanceIds());

        $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'nn']);
        $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'nn']);

        self::assertSame(['acme--nn', 'acme--nn-1', 'acme--nn-2', 'acme--nn-3'], $this->instanceIds());
    }

    public function testDeployingAtTheSameTimeIsAccepted(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'par', 'deploy[count]' => 3, 'deploy[parallel]' => true]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['acme--par-1', 'acme--par-2', 'acme--par-3'], $this->instanceIds());
        foreach ($this->jobs() as $job) {
            self::assertFalse($job->isSequential());
        }
    }

    public function testSeveralInstancesDeployOneAfterTheOtherByDefault(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'seq', 'deploy[count]' => 2]);

        foreach ($this->jobs() as $job) {
            self::assertTrue($job->isSequential());
        }
    }

    public function testALabelWithNoLetterOrDigitKeepsWhatWasTyped(): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => '!!!', 'deploy[branch]' => 'dev']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The label needs at least one letter or digit.', $this->formText($crawler));
        self::assertCount(1, $crawler->filter('input[name="deploy[label]"][aria-invalid=true]'));
        $values = $crawler->selectButton('Deploy')->form()->getValues();
        self::assertSame('!!!', $values['deploy[label]']);
        self::assertSame('dev', $values['deploy[branch]']);
        self::assertSame('acme', $values['deploy[project]']);
        self::assertSame([], $this->jobs());
    }

    public function testALabelThatMakesTheIdTooLongIsRefused(): void
    {
        // "acme--" and 60 more characters; the field's own limit of 40 is a browser concern, the server checks too.
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => str_repeat('l', 60)]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The instance id would be 66 characters; the limit is 59 (DDEV adds -web to the container host name, and a host name has at most 63 characters). Use a shorter label.', $this->formText($crawler));
        self::assertSame([], $this->jobs());
    }

    #[DataProvider('badCounts')]
    public function testACountOutsideOneToTwentyIsRefused(string $count): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[label]' => 'ok', 'deploy[count]' => $count]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The count must be between 1 and 20.', $this->formText($crawler));
        self::assertSame($count, $crawler->selectButton('Deploy')->form()->getValues()['deploy[count]']);
        self::assertSame([], $this->jobs());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCounts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above the maximum' => ['21'];
        yield 'negative' => ['-3'];
    }

    public function testACountThatIsNotANumberIsRefused(): void
    {
        $crawler = $this->rawDeploy(['project' => 'acme', 'label' => 'ok', 'count' => 'abc']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a whole number.', $this->formText($crawler));
        self::assertSame([], $this->jobs());
    }

    #[DataProvider('badBranches')]
    public function testEachBranchRuleShowsItsMessageAndKeepsTheValue(string $branch, string $message): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[branch]' => $branch, 'deploy[label]' => 'ok']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->formText($crawler));
        self::assertCount(1, $crawler->filter('input[name="deploy[branch]"][aria-invalid=true]'));
        self::assertSame($branch, $crawler->selectButton('Deploy')->form()->getValues()['deploy[branch]']);
        self::assertSame([], $this->jobs());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badBranches(): iterable
    {
        yield 'space' => ['bad branch', self::BRANCH_CHARS];
        yield 'leading dash' => ['-rf', self::BRANCH_CHARS];
        yield 'double dot' => ['a..b', self::BRANCH_GIT];
        yield 'double slash' => ['a//b', 'This is not a valid git branch name'];
        yield 'trailing slash' => ['topic/', 'This is not a valid git branch name'];
        yield 'lock ending' => ['topic.lock', 'This is not a valid git branch name'];
        yield 'HEAD' => ['HEAD', self::BRANCH_REF];
        yield 'full reference' => ['refs/heads/main', self::BRANCH_REF];
        yield 'too long' => [str_repeat('b', 201), 'The branch name must be at most 200 characters.'];
    }

    public function testTheErrorsOfSeveralFieldsComeTogether(): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[branch]' => 'a..b', 'deploy[label]' => 'ok', 'deploy[count]' => 0]);

        self::assertResponseStatusCodeSame(422);
        $text = $this->formText($crawler);
        self::assertStringContainsString('This is not a valid git branch name', $text);
        self::assertStringContainsString('The count must be between 1 and 20.', $text);
    }

    public function testATemplateAndAProjectThatAreNotInTheListsAreRefused(): void
    {
        $crawler = $this->rawDeploy(['project' => 'acme', 'template' => 'ghost']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This project has no template with that name.', $this->formText($crawler));

        $crawler = $this->rawDeploy(['project' => 'ghost']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose one of the projects.', $this->formText($crawler));
        self::assertSame([], $this->jobs());
    }

    public function testAProjectWithNoDefaultBranchOrTemplateAsksForBothAndDeploysOnceTheyAreGiven(): void
    {
        $crawler = $this->open('/instances/new?project=bare');
        self::assertSame('', (string) $crawler->filter('input[name="deploy[branch]"]')->attr('placeholder'));

        $crawler = $this->deploy(['deploy[project]' => 'bare'], '/instances/new?project=bare');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a branch: the project has no default branch.', $this->formText($crawler));
        self::assertStringContainsString('Choose a template: the project has no default template.', $this->formText($crawler));

        $crawler = $this->submit($crawler, 'Deploy', ['deploy[branch]' => 'develop']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a template: the project has no default template.', $this->formText($crawler));
        self::assertStringNotContainsString('Choose a branch', $this->formText($crawler));

        $this->submit($crawler, 'Deploy', ['deploy[template]' => 'only']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(['bare--develop'], $this->instanceIds());
    }

    public function testALabelThatPushesAnAliasHostNameOver63CharactersIsRefused(): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'alias', 'deploy[label]' => str_repeat('l', 30)]);

        self::assertResponseStatusCodeSame(422);
        self::assertMatchesRegularExpression('/The host name label "<hostname>-<id>" would be \d+ characters; the limit is 63\. Use a shorter label\./', $this->formText($crawler));
        self::assertSame([], $this->instanceIds());

        $this->submit($crawler, 'Deploy', ['deploy[label]' => 'short']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(['alias--short'], $this->instanceIds());
        self::assertStringContainsString(str_repeat('h', 30).'-alias--short.fleet.example.com', $this->open('/instances/alias--short')->filter('[data-testid=instance-alias]')->text());
    }
}
