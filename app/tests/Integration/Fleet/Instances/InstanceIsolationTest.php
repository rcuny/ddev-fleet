<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\InstanceIsolation;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class InstanceIsolationTest extends KernelTestCase
{
    use FleetDatabase;

    private TemplateRepository $templates;
    private RegisteredSecretsMasker $masker;
    private InstanceIsolation $isolation;
    private Project $project;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->templates = new TemplateRepository($em);
        $this->masker = new RegisteredSecretsMasker();
        $this->isolation = new InstanceIsolation($this->templates, $this->masker, 'test-secret');
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $em->persist($this->project);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function instance(string $template, bool $database, bool $agent, string $label = 'main'): Instance
    {
        $stored = new Template($this->project, $template);
        $stored->changeIsolation($database, $agent);
        $this->templates->save($stored);

        return new Instance($this->project, $label, $template, 'develop', 'admin');
    }

    public function testMailpitGetsAPerInstancePasswordThatIsMasked(): void
    {
        $a = $this->isolation->mailpitCredentials('acme--a');
        $b = $this->isolation->mailpitCredentials('acme--b');

        self::assertSame('fleet', $a['user']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $a['password']);
        self::assertNotSame($a['password'], $b['password']);
        self::assertSame($a, $this->isolation->mailpitCredentials('acme--a'));
        self::assertNotSame($a['password'], new InstanceIsolation($this->templates, $this->masker, 'other-secret')->mailpitCredentials('acme--a')['password']);

        $instance = $this->instance('full', true, false);
        $variables = $this->isolation->variables($instance, $this->project);
        $own = $this->isolation->mailpitCredentials($instance->id());
        self::assertContains('MP_UI_AUTH=fleet:'.$own['password'], $variables);
        self::assertSame('[masked]', $this->masker->mask($own['password']));
    }

    public function testATemplateWithADatabaseSaysSoAndPoisonsNothing(): void
    {
        $variables = $this->isolation->variables($this->instance('full', true, false), $this->project);

        self::assertContains('FLEET_HAS_DB=1', $variables);
        self::assertNotContains('FLEET_AGENT=1', $variables);
        self::assertSame([], array_filter($variables, static fn (string $v): bool => str_starts_with($v, 'DB_HOST=')));
    }

    public function testADatabaseLessAgentTemplateGetsAPoisonedHostAndTheAgentFlag(): void
    {
        $variables = $this->isolation->variables($this->instance('agent', false, true), $this->project);

        self::assertContains('FLEET_HAS_DB=0', $variables);
        self::assertContains('FLEET_AGENT=1', $variables);
        foreach (['DB_HOST', 'DATABASE_HOST', 'MYSQL_HOST'] as $name) {
            self::assertContains($name.'=fleet-no-database.invalid', $variables);
        }
    }

    public function testTheComposeOverrideMountsTheTraefikTreeReadOnly(): void
    {
        $dir = $this->tempHome.'/checkout';
        mkdir($dir.'/.ddev', 0o750, true);

        self::assertSame('.ddev/docker-compose.fleet-isolation.yaml', $this->isolation->write($dir));

        $override = Yaml::parseFile($dir.'/.ddev/docker-compose.fleet-isolation.yaml');
        self::assertSame(
            [['type' => 'volume', 'source' => 'ddev-global-cache', 'target' => '/mnt/ddev-global-cache/traefik', 'read_only' => true, 'volume' => ['subpath' => 'traefik']]],
            $override['services']['web']['volumes'],
        );
        self::assertSame(0o640, fileperms($dir.'/.ddev/docker-compose.fleet-isolation.yaml') & 0o777);
    }
}
