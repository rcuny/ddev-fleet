<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private TemplateRepository $templates;
    private Project $blog;
    private Project $shop;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->templates = new TemplateRepository($this->em);
        $projects = new ProjectRepository($this->em);
        $this->blog = new Project('blog', 'https://git.example.com/acme/blog.git');
        $this->shop = new Project('shop', 'https://git.example.com/acme/shop.git');
        $projects->save($this->blog);
        $projects->save($this->shop);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function reload(string $name): Project
    {
        return new ProjectRepository($this->em)->findByName($name) ?? self::fail('No such project.');
    }

    public function testNameIsUniquePerProjectButNotAcrossProjects(): void
    {
        $this->templates->save(new Template($this->blog, 'full'));
        $this->templates->save(new Template($this->shop, 'full'));
        $this->em->clear();

        self::assertSame('blog', $this->templates->find($this->reload('blog'), 'full')?->project()->name());
        self::assertSame('shop', $this->templates->find($this->reload('shop'), 'full')?->project()->name());
        self::assertNull($this->templates->find($this->reload('blog'), 'nope'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->templates->save(new Template($this->reload('blog'), 'full'));
    }

    public function testCommandListsKeepOrderAndDuplicates(): void
    {
        $created = new \DateTimeImmutable('2026-10-01 08:00:00');
        $changed = new \DateTimeImmutable('2026-10-02 09:30:00');
        $template = new Template($this->blog, 'full', $created);
        $template->changeDrupalEnv('dev', $changed);
        $template->changeCommands(
            ['composer install', 'drush cr', 'composer install'],
            ['ddev logs -f', 'ddev logs -f'],
            ['echo "ü — 日本語"'],
            $changed,
        );
        $this->templates->save($template);
        $this->em->clear();

        $found = $this->templates->find($this->reload('blog'), 'full') ?? self::fail();
        self::assertSame('dev', $found->drupalEnv());
        self::assertSame(['composer install', 'drush cr', 'composer install'], $found->postDeploy());
        self::assertSame(['ddev logs -f', 'ddev logs -f'], $found->tty1());
        self::assertSame(['echo "ü — 日本語"'], $found->tty2());
        self::assertEquals($created, $found->createdAt());
        self::assertEquals($changed, $found->updatedAt());
    }

    public function testANewTemplateIsEmpty(): void
    {
        $this->templates->save(new Template($this->blog, 'bare'));
        $this->em->clear();

        $found = $this->templates->find($this->reload('blog'), 'bare') ?? self::fail();
        self::assertNull($found->drupalEnv());
        self::assertSame([], $found->postDeploy());
        self::assertSame([], $found->tty1());
        self::assertSame([], $found->tty2());
    }

    public function testRemoveDeletesOnlyThatTemplate(): void
    {
        $this->templates->save(new Template($this->blog, 'full'));
        $this->templates->save(new Template($this->blog, 'light'));
        $this->em->clear();
        $blog = $this->reload('blog');

        $this->templates->remove($this->templates->find($blog, 'full') ?? self::fail());

        self::assertNull($this->templates->find($blog, 'full'));
        self::assertNotNull($this->templates->find($blog, 'light'));
    }
}
