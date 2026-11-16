<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceIsolation;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Instances\MailpitExposure;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyReloader;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SystemTemplates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * FLE-344: the Mailpit site of an instance in the Caddyfile snippet (template on and off, both TLS modes, the gate, the
 * upstream header, the host label limit).
 */
final class InstanceMailpitSiteTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private InstanceSiteWriter $writer;
    private HostSettings $settings;
    private Project $project;
    private Template $template;
    private RegisteredSecretsMasker $masker;
    private InstanceIsolation $isolation;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $host = new HostSettingsRepository($em);
        $this->settings = $host->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $runner = new RecordingCommandRunner();
        $templates = new TemplateRepository($em);
        $this->masker = new RegisteredSecretsMasker();
        $hostnames = new InstanceHostnames($host);
        $this->isolation = new InstanceIsolation($templates, $this->masker, $runner, 'test-secret');
        $this->writer = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->paths,
            new CaddyReloader($runner, $this->paths),
            $hostnames,
            new LockFactory(new FlockStore($this->paths->locksDir())),
            new AdminGroup('fleet-admins'),
            new ProjectRepository($em),
            new MailpitExposure($templates, $this->isolation, $hostnames, $this->masker),
        );
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        new ProjectRepository($em)->save($this->project);
        $this->template = new Template($this->project, 'full', new \DateTimeImmutable());
        $templates->save($this->template);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function site(string $id): string
    {
        return (string) file_get_contents($this->paths->instanceSiteFile($id));
    }

    private function expectedAuthorization(string $id): string
    {
        $credentials = $this->isolation->mailpitCredentials($id);

        return 'Basic '.base64_encode($credentials['user'].':'.$credentials['password']);
    }

    public function testNoMailpitSiteWhileTheTemplateOptionIsOff(): void
    {
        $this->writer->write(new Instance($this->project, 'main', 'full', 'develop', 'admin'), $this->project);

        self::assertStringNotContainsString('mailpit', $this->site('acme--main'));
    }

    public function testHttp01GetsASiteBlockBehindTheInstanceGate(): void
    {
        $this->template->changeMailpit(true);
        $this->writer->write(new Instance($this->project, 'main', 'full', 'develop', 'admin'), $this->project);
        $site = $this->site('acme--main');

        self::assertStringContainsString("\nmailpit--acme--main.fleet.example.com {\n", $site);
        $mail = substr($site, (int) strpos($site, "\nmailpit--acme--main.fleet.example.com {"));
        self::assertStringContainsString('forward_auth', $mail);
        self::assertStringContainsString('not header_regexp Remote-Groups `(?:^|,)(?:fleet\\-admins|fleet\\-project\\-acme)(?:,|$)`', $mail);
        self::assertStringContainsString('respond @forbidden_mp_acme--main "Forbidden" 403', $mail);
        self::assertStringContainsString('reverse_proxy 127.0.0.1:8025 {', $mail);
        self::assertStringContainsString('header_up Host acme--main.fleet.example.com', $mail);
        self::assertStringContainsString('header_up Authorization "'.$this->expectedAuthorization('acme--main').'"', $mail);
        self::assertLessThan(strpos($mail, 'reverse_proxy'), strpos($mail, 'forward_auth'), 'the gate comes before the proxy');
        self::assertSame(0o640, fileperms($this->paths->instanceSiteFile('acme--main')) & 0o777, 'the snippet holding the Mailpit header is not world readable');
    }

    public function testOvhDnsGetsAMatcherAndAHandleInsideTheWildcardSite(): void
    {
        $this->settings->changeTlsMode(TlsMode::OvhDns, new \DateTimeImmutable());
        $this->template->changeMailpit(true);
        $this->writer->write(new Instance($this->project, 'main', 'full', 'develop', 'admin'), $this->project);
        $site = $this->site('acme--main');

        self::assertStringContainsString("\n@m_acme--main host mailpit--acme--main.fleet.example.com\nhandle @m_acme--main {\n", $site);
        self::assertSame(0, preg_match_all('/^mailpit--\S* \{$/m', $site), 'no site block (no certificate of its own)');
        self::assertStringContainsString('reverse_proxy 127.0.0.1:8025 {', $site);
        self::assertStringContainsString('header_up Host acme--main.fleet.example.com', $site);
    }

    public function testTheMailpitCredentialIsRegisteredWithTheLogMasker(): void
    {
        $this->template->changeMailpit(true);
        $this->writer->write(new Instance($this->project, 'main', 'full', 'develop', 'admin'), $this->project);
        $password = $this->isolation->mailpitCredentials('acme--main')['password'];

        self::assertStringNotContainsString($password, $this->masker->mask("password {$password}"));
    }

    public function testAnIdTooLongForTheMailpitLabelGetsNoRoute(): void
    {
        $this->template->changeMailpit(true);
        // `acme--` + 52 = 58 characters is a valid instance id (max 59), but `mailpit--` + 58 is 67 > 63.
        $instance = new Instance($this->project, str_repeat('a', 52), 'full', 'develop', 'admin');
        self::assertTrue(\strlen($instance->id()) <= 59);
        self::assertFalse(MailpitExposure::fits($instance->id()));

        $this->writer->write($instance, $this->project);

        self::assertStringNotContainsString('mailpit', $this->site($instance->id()));
    }

    public function testTheLabelLimitIsExactlySixtyThree(): void
    {
        self::assertTrue(MailpitExposure::fits(str_repeat('a', 54)));
        self::assertFalse(MailpitExposure::fits(str_repeat('a', 55)));
    }
}
