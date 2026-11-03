<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Export;

use App\Command\Support\PassphraseInput;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\ExportFixtures;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExportImportCommandsTest extends KernelTestCase
{
    use ExportFixtures;
    use FleetDatabase;
    use RunsFleetCommands;

    private const string PASSPHRASE = 'correct horse battery';

    private string $scratch = '';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->scratch = sys_get_temp_dir().'/fleet-export-cli-'.bin2hex(random_bytes(5));
        mkdir($this->scratch, 0o700);
        putenv(PassphraseInput::ENV);
    }

    protected function tearDown(): void
    {
        putenv(PassphraseInput::ENV);
        foreach (glob($this->scratch.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->scratch);
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function switchToEmptyHost(): void
    {
        $this->tearDownFleetDatabase();
        $this->setUpFleetDatabase();
    }

    private function exportToFile(string $name = 'blog.json', ?string $passphrase = null): string
    {
        $file = $this->scratch.'/'.$name;
        if (null !== $passphrase) {
            putenv(PassphraseInput::ENV.'='.$passphrase);
        }
        $tester = $this->cli('fleet:export:project', ['slug' => 'blog', '--output' => $file] + (null === $passphrase ? [] : ['--with-secrets' => true]));
        putenv(PassphraseInput::ENV);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());

        return $file;
    }

    public function testExportPrintsTheFileToStdoutAndNothingElse(): void
    {
        $this->seedBlog();

        $tester = $this->cli('fleet:export:project', ['slug' => 'blog']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
        $document = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('fleet-project-export', $document['format']);
        self::assertStringNotContainsString(self::SECRET_VALUE, $tester->getDisplay());
    }

    public function testExportToAFileIsPrivateAndNeverOverwritesWithoutForce(): void
    {
        $this->seedBlog();
        $file = $this->exportToFile();

        self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4));
        $again = $this->cli('fleet:export:project', ['slug' => 'blog', '--output' => $file]);
        self::assertSame(2, $again->getStatusCode());
        self::assertStringContainsString('--force', $again->getErrorOutput());
        self::assertSame(0, $this->cli('fleet:export:project', ['slug' => 'blog', '--output' => $file, '--force' => true])->getStatusCode());
        self::assertSame([], glob($this->scratch.'/.export-*') ?: [], 'No temporary file is left behind.');
    }

    public function testExportOfAnUnknownProjectIsExitThree(): void
    {
        self::assertSame(3, $this->cli('fleet:export:project', ['slug' => 'ghost'])->getStatusCode());
    }

    public function testThePassphraseIsNeverAnOptionOrAnArgument(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        foreach (['fleet:export:project', 'fleet:import:preview', 'fleet:import:apply'] as $name) {
            $definition = $application->find($name)->getDefinition();
            foreach (array_keys($definition->getOptions()) as $option) {
                self::assertStringNotContainsString('passphrase=', $option);
                self::assertFalse($definition->getOption($option)->acceptValue() && str_contains($option, 'passphrase'), "$name --$option would put the passphrase in argv.");
            }
            foreach (array_keys($definition->getArguments()) as $argument) {
                self::assertStringNotContainsString('passphrase', $argument);
            }
        }
    }

    public function testSecretsNeedAPassphraseAndNonInteractiveRunsSayHowToGiveOne(): void
    {
        $this->seedBlog();

        $tester = $this->cli('fleet:export:project', ['slug' => 'blog', '--with-secrets' => true]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString(PassphraseInput::ENV, $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testPassphraseFromTheEnvironmentAndFromStandardInput(): void
    {
        $this->seedBlog();
        $viaEnv = $this->exportToFile('env.json', self::PASSPHRASE);
        self::assertStringContainsString('"secrets"', (string) file_get_contents($viaEnv));
        self::assertStringNotContainsString(self::SECRET_VALUE, (string) file_get_contents($viaEnv));

        $viaStdin = $this->cli('fleet:export:project', ['slug' => 'blog', '--with-secrets' => true, '--passphrase-stdin' => true], [self::PASSPHRASE]);
        self::assertSame(0, $viaStdin->getStatusCode(), $viaStdin->getErrorOutput());
        self::assertStringContainsString('"secrets"', $viaStdin->getDisplay());
        self::assertStringNotContainsString(self::PASSPHRASE, $viaStdin->getDisplay().$viaStdin->getErrorOutput());
    }

    public function testAShortPassphraseIsRefusedWithExitOne(): void
    {
        $this->seedBlog();
        putenv(PassphraseInput::ENV.'=short');

        $tester = $this->cli('fleet:export:project', ['slug' => 'blog', '--with-secrets' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('short', str_replace('The passphrase needs', '', $tester->getErrorOutput()));
    }

    public function testPreviewShowsTheDiffAndWritesNothing(): void
    {
        $this->seedBlog();
        $file = $this->exportToFile();
        $this->switchToEmptyHost();
        $before = $this->fingerprint();

        $json = $this->cli('fleet:import:preview', ['file' => $file, '--json' => true]);
        self::assertSame(0, $json->getStatusCode(), $json->getErrorOutput());
        $plan = $this->json($json);
        self::assertTrue($plan['valid']);
        self::assertSame('blog', $plan['project']);
        self::assertSame(8, $plan['counts']['create'], 'port, project, 2 templates, 2 webhooks, 2 schedules');
        self::assertSame($before, $this->fingerprint());

        $table = $this->cli('fleet:import:preview', ['file' => $file]);
        self::assertSame(0, $table->getStatusCode());
        self::assertStringContainsString('create', $table->getDisplay());
        self::assertStringContainsString('blog-nightly', $table->getDisplay());
        self::assertSame($before, $this->fingerprint());
    }

    public function testApplyNeedsConfirmationThenImportsAndTheSecondRunIsUnchanged(): void
    {
        $this->seedBlog();
        $file = $this->exportToFile();
        $this->switchToEmptyHost();

        $refused = $this->cli('fleet:import:apply', ['file' => $file]);
        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('--yes', $refused->getErrorOutput());
        self::assertSame(0, $this->fingerprint()['project']);

        $done = $this->cli('fleet:import:apply', ['file' => $file, '--yes' => true, '--json' => true]);
        self::assertSame(0, $done->getStatusCode(), $done->getErrorOutput());
        self::assertTrue($this->json($done)['imported']);
        self::assertSame(1, $this->fingerprint()['project']);

        $exported = $this->cli('fleet:export:project', ['slug' => 'blog']);
        self::assertSame((string) file_get_contents($file), $exported->getDisplay(), 'Export, import, export: the same bytes.');

        $second = $this->cli('fleet:import:apply', ['file' => $file, '--yes' => true, '--json' => true]);
        self::assertSame(0, $second->getStatusCode());
        $plan = $this->json($second);
        self::assertSame(0, $plan['counts']['create'] + $plan['counts']['update']);
        self::assertArrayNotHasKey('imported', $plan);
    }

    public function testAnInvalidFileIsExitOneAndWritesNothing(): void
    {
        $file = $this->scratch.'/bad.json';
        file_put_contents($file, '{"format":"fleet-project-export","version":3}');
        $before = $this->fingerprint();

        foreach (['fleet:import:preview', 'fleet:import:apply'] as $command) {
            $tester = $this->cli($command, ['file' => $file] + ('fleet:import:apply' === $command ? ['--yes' => true] : []));
            self::assertSame(1, $tester->getStatusCode(), $command);
            self::assertStringContainsString('version 3', $tester->getErrorOutput());
        }
        $missing = $this->cli('fleet:import:preview', ['file' => $this->scratch.'/none.json']);
        self::assertSame(1, $missing->getStatusCode());
        self::assertSame($before, $this->fingerprint());
    }

    public function testSecretsImportWithThePassphraseAndAreSkippedWithoutIt(): void
    {
        $this->seedBlog();
        $file = $this->exportToFile('sealed.json', self::PASSPHRASE);

        $this->switchToEmptyHost();
        $without = $this->cli('fleet:import:apply', ['file' => $file, '--yes' => true, '--json' => true]);
        self::assertSame(0, $without->getStatusCode(), $without->getErrorOutput());
        self::assertStringContainsString('not imported', $without->getDisplay());
        self::assertSame(0, $this->fingerprint()['secret']);

        $this->switchToEmptyHost();
        $wrong = $this->cli('fleet:import:apply', ['file' => $file, '--yes' => true, '--passphrase-stdin' => true], ['not the passphrase']);
        self::assertSame(1, $wrong->getStatusCode());
        self::assertStringContainsString('passphrase is wrong', $wrong->getErrorOutput());
        self::assertSame(0, $this->fingerprint()['project'], 'A wrong passphrase writes nothing at all.');
        self::assertStringNotContainsString('not the passphrase', $wrong->getDisplay().$wrong->getErrorOutput());

        $right = $this->cli('fleet:import:apply', ['file' => $file, '--yes' => true, '--passphrase-stdin' => true, '--json' => true], [self::PASSPHRASE]);
        self::assertSame(0, $right->getStatusCode(), $right->getErrorOutput());
        self::assertStringNotContainsString(self::SECRET_VALUE, $right->getDisplay().$right->getErrorOutput());
        $store = $this->service(SecretStore::class);
        \assert($store instanceof SecretStore);
        self::assertSame(self::SECRET_VALUE, $store->getManaged('blog', 'JIRA_TOKEN'));
    }
}
