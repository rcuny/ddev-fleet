<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Template;

use App\Domain\Template\Template;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $projects = self::getContainer()->get(ProjectService::class);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function stored(string $project, string $name): Template
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(TemplateService::class)->get($project, $name);
    }

    public function testCreateWithCommandsAndShow(): void
    {
        $created = $this->cli('fleet:template:create', [
            'project' => 'blog',
            'name' => 'web',
            '--drupal-env' => 'dev',
            '--post-deploy' => ['composer install', 'drush deploy'],
            '--tty1' => ['npm run watch'],
            '--tty2' => ['tail -f var/log/dev.log'],
            '--json' => true,
        ]);

        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertSame('', $created->getErrorOutput());
        $object = $this->json($created);
        self::assertSame('blog', $object['project']);
        self::assertSame('web', $object['name']);
        self::assertSame('dev', $object['drupal_env']);
        self::assertSame(['exec' => ['composer install', 'drush deploy'], 'tty1' => ['npm run watch'], 'tty2' => ['tail -f var/log/dev.log']], $object['post_deploy']);
        self::assertSame(['project', 'name', 'drupal_env', 'post_deploy', 'created_at', 'updated_at'], array_keys($object));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $object['created_at']);

        self::assertSame($object, $this->json($this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web', '--format' => 'json'])));

        $show = $this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web']);
        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString("project: blog\nname: web\ndrupal env: dev\npost-deploy:\n  - composer install\n  - drush deploy\ntty1:\n  - npm run watch\ntty2:\n  - tail -f var/log/dev.log\n", $show->getDisplay());
    }

    public function testListAllProjectsAndOne(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--drupal-env' => 'dev', '--post-deploy' => ['composer install']]);
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'full']);
        $this->cli('fleet:template:create', ['project' => 'shop', 'name' => 'web']);

        $all = $this->cli('fleet:template:list');
        self::assertSame(0, $all->getStatusCode());
        $text = $this->squash($all->getDisplay());
        foreach (['PROJECT', 'NAME', 'DRUPAL ENV', 'POST-DEPLOY', 'TTY1', 'TTY2'] as $header) {
            self::assertStringContainsString($header, $text);
        }
        self::assertMatchesRegularExpression('#\bblog full - 0 0 0\b#', $text);
        self::assertMatchesRegularExpression('#\bblog web dev 1 0 0\b#', $text);
        self::assertMatchesRegularExpression('#\bshop web - 0 0 0\b#', $text);

        $one = $this->json($this->cli('fleet:template:list', ['project' => 'blog', '--json' => true]));
        self::assertSame([['blog', 'full'], ['blog', 'web']], array_map(static fn (array $t): array => [$t['project'], $t['name']], $one));
        self::assertCount(3, $this->json($this->cli('fleet:template:list', ['--json' => true])));
        self::assertCount(1, $this->json($this->cli('fleet:template:list', ['project' => 'shop', '--json' => true])));
    }

    public function testListOfAnUnknownProjectExits3(): void
    {
        $list = $this->cli('fleet:template:list', ['project' => 'nope']);

        self::assertSame(3, $list->getStatusCode());
        self::assertStringContainsString('Project "nope" does not exist.', $list->getErrorOutput());
    }

    public function testBadCommandLineExits1WithLineNumber(): void
    {
        $bad = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--post-deploy' => ['composer install', "drush\tdeploy"]]);

        self::assertSame(1, $bad->getStatusCode());
        self::assertSame('', $bad->getDisplay());
        self::assertStringStartsWith('--post-deploy: Command line 2 contains a control character', $bad->getErrorOutput());
        $this->expectException(NotFound::class);
        $this->stored('blog', 'web');
    }

    public function testErrorsNameTheArgumentsAndOptions(): void
    {
        $bad = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'Bad', '--drupal-env' => 'a b', '--tty1' => ["x\e[31m"]]);

        self::assertSame(1, $bad->getStatusCode());
        $keys = array_map(static fn (string $line): string => explode(': ', $line, 2)[0], explode("\n", trim($bad->getErrorOutput())));
        self::assertSame(['<name>', '--drupal-env', '--tty1'], $keys);

        $json = $this->json($this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'Bad', '--json' => true]));
        self::assertSame(['<name>'], array_keys($json['fields']));
    }

    public function testMultiLineTextIsSplitIntoLinesLikeATextarea(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--post-deploy' => ["one\r\n\r\ntwo\n  three  "]]);

        self::assertSame(['one', 'two', 'three'], $this->stored('blog', 'web')->postDeploy());
    }

    public function testUpdateChangesOnlyGivenOptionsAndUnsetClears(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--drupal-env' => 'dev', '--post-deploy' => ['a'], '--tty1' => ['b'], '--tty2' => ['c']]);

        $update = $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--tty2' => ['d', 'e'], '--json' => true]);
        self::assertSame(0, $update->getStatusCode(), $update->getErrorOutput());
        $template = $this->stored('blog', 'web');
        self::assertSame('dev', $template->drupalEnv());
        self::assertSame(['a'], $template->postDeploy());
        self::assertSame(['b'], $template->tty1());
        self::assertSame(['d', 'e'], $template->tty2());
        self::assertSame(['d', 'e'], $this->json($update)['post_deploy']['tty2'] ?? null);
    }

    public function testUnsetTty1(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--drupal-env' => 'dev', '--post-deploy' => ['a'], '--tty1' => ['b']]);

        $unset = $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--unset' => ['tty1', 'drupal-env']]);

        self::assertSame(0, $unset->getStatusCode(), $unset->getErrorOutput());
        $template = $this->stored('blog', 'web');
        self::assertSame([], $template->tty1());
        self::assertNull($template->drupalEnv());
        self::assertSame(['a'], $template->postDeploy());
    }

    public function testUnsetErrors(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--tty1' => ['b']]);

        $word = $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--unset' => ['name']]);
        $both = $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--unset' => ['tty1'], '--tty1' => ['c']]);

        self::assertSame(1, $word->getStatusCode());
        self::assertSame(1, $both->getStatusCode());
        self::assertStringStartsWith('--unset: ', $word->getErrorOutput());
        self::assertStringStartsWith('--unset: ', $both->getErrorOutput());
        self::assertSame(['b'], $this->stored('blog', 'web')->tty1());
    }

    public function testNotFoundExits3(): void
    {
        self::assertSame(3, $this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'nope'])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:template:show', ['project' => 'nope', 'name' => 'web'])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'nope', '--tty1' => ['x']])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:template:create', ['project' => 'nope', 'name' => 'web'])->getStatusCode());
        $json = $this->cli('fleet:template:delete', ['project' => 'blog', 'name' => 'nope', '--yes' => true, '--json' => true]);
        self::assertSame(3, $json->getStatusCode());
        self::assertSame('Template "nope" does not exist in project "blog".', $this->json($json)['error']);
    }

    public function testDuplicateNameIsAFieldError(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web']);

        $again = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web']);

        self::assertSame(1, $again->getStatusCode());
        self::assertStringStartsWith('<name>: A template with this name already exists', $again->getErrorOutput());
    }

    public function testDeleteNeedsYesAndDeletes(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web']);

        $refused = $this->cli('fleet:template:delete', ['project' => 'blog', 'name' => 'web']);
        self::assertSame(2, $refused->getStatusCode());
        self::assertSame('web', $this->stored('blog', 'web')->name());

        $deleted = $this->cli('fleet:template:delete', ['project' => 'blog', 'name' => 'web', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode(), $deleted->getErrorOutput());
        self::assertSame(['deleted' => 'web'], $this->json($deleted));
        self::assertSame(3, $this->cli('fleet:template:show', ['project' => 'blog', 'name' => 'web'])->getStatusCode());
    }

    public function testDeleteDefaultTemplateRefused(): void
    {
        $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web']);
        $this->cli('fleet:project:update', ['name' => 'blog', '--default-template' => 'web']);

        $refused = $this->cli('fleet:template:delete', ['project' => 'blog', 'name' => 'web', '--yes' => true]);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('default template', $refused->getErrorOutput());
        self::assertSame('web', $this->stored('blog', 'web')->name());
    }
}
