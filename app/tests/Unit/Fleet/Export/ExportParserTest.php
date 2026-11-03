<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Export;

use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\ExportParser;
use App\Fleet\Export\InvalidExport;
use PHPUnit\Framework\TestCase;

final class ExportParserTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return [
            'format' => 'fleet-project-export',
            'version' => 1,
            'project' => [
                'name' => 'blog',
                'git' => 'https://git.example.com/acme/blog.git',
                'default_branch' => null,
                'default_template' => null,
                'additional_hostnames' => [],
                'ports' => [],
                'git_bot' => ['mode' => 'inherit', 'name' => null, 'email' => null],
                'issue_id_regexp' => null,
                'display_submodule_branch' => null,
                'typesense' => false,
            ],
            'ports' => [],
            'templates' => [],
            'webhooks' => [],
            'schedules' => [],
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function problems(array $document): array
    {
        try {
            new ExportParser()->parse(ExportFormat::encode($document));
        } catch (InvalidExport $e) {
            return $e->problems;
        }
        self::fail('The document must be refused.');
    }

    public function testAMinimalDocumentParsesToItsCanonicalShape(): void
    {
        $parsed = new ExportParser()->parse(ExportFormat::encode(self::document()));

        self::assertSame(self::document() + ['secrets' => null], $parsed);
    }

    public function testEncodingIsStableAndEndsWithANewline(): void
    {
        $text = ExportFormat::encode(self::document());

        self::assertSame($text, ExportFormat::encode(json_decode($text, true, 512, \JSON_THROW_ON_ERROR)));
        self::assertStringEndsWith("\n", $text);
        self::assertStringContainsString('"https://git.example.com/acme/blog.git"', $text, 'Slashes are not escaped.');
    }

    public function testTheVersionIsCheckedBeforeTheShape(): void
    {
        $newer = ['version' => 7] + self::document();
        $newer['project'] = 'whatever';

        try {
            new ExportParser()->parse(ExportFormat::encode($newer));
            self::fail('Refused.');
        } catch (InvalidExport $e) {
            self::assertStringContainsString('version 7', $e->getMessage());
            self::assertStringContainsString('Update this host', $e->getMessage());
        }
    }

    public function testEveryProblemIsListedWithItsPath(): void
    {
        $document = self::document();
        $document['project']['typesense'] = 'no';
        $document['templates'] = [['name' => 'x', 'drupal_env' => null, 'post_deploy' => ['exec' => 'ls', 'tty1' => [], 'tty2' => []]]];
        $document['schedules'] = [['name' => 'n', 'cron' => 1, 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => null, 'enabled' => true]];

        self::assertSame([
            'project.typesense: expected true or false.',
            'templates[0].post_deploy.exec: expected a list.',
            'schedules[0].cron: expected a string.',
        ], $this->problems($document));
    }

    public function testAMissingOrUnknownKeyIsNamedAndTheObjectIsNotReadFurther(): void
    {
        $document = self::document();
        unset($document['project']['git']);
        $document['templates'] = [['name' => 'x', 'drupal_env' => null, 'post_deploy' => ['exec' => [], 'tty1' => [], 'tty2' => []], 'bonus' => 1]];

        self::assertSame(['project.git: missing.', 'templates[0].bonus: unknown key.'], $this->problems($document));
    }

    public function testAnUnknownKeyIsNamedOnlyWhenItLooksLikeAKey(): void
    {
        $document = self::document();
        $document["pass\nword=hunter2"] = 1;

        self::assertSame(['(key): unknown key.'], $this->problems($document));
    }

    public function testUpToFiftyAdditionalHostnamesAreAccepted(): void
    {
        $document = self::document();
        $document['project']['additional_hostnames'] = array_map(static fn (int $i): string => 'h'.$i, range(1, 23));

        self::assertSame($document['project']['additional_hostnames'], new ExportParser()->parse(ExportFormat::encode($document))['project']['additional_hostnames']);

        $document['project']['additional_hostnames'] = array_map(static fn (int $i): string => 'h'.$i, range(1, 51));

        self::assertNotSame([], $this->problems($document));
    }

    public function testAProjectPortMustBeDescribedAndNamesMustBeUnique(): void
    {
        $document = self::document();
        $document['project']['ports'] = ['typesense'];
        $document['templates'] = [
            ['name' => 'a', 'drupal_env' => null, 'post_deploy' => ['exec' => [], 'tty1' => [], 'tty2' => []]],
            ['name' => 'a', 'drupal_env' => null, 'post_deploy' => ['exec' => [], 'tty1' => [], 'tty2' => []]],
        ];

        self::assertSame(['project.ports[0]: the port is not described in ports.', 'templates: an entry appears twice.'], $this->problems($document));
    }

    public function testTheSealedBlockHasBoundedCostParameters(): void
    {
        $document = self::document();
        $document['secrets'] = ['kdf' => 'argon2id13', 'opslimit' => 99, 'memlimit' => 1, 'salt' => '!!', 'nonce' => 'AA==', 'ciphertext' => 'AA=='];

        self::assertSame([
            'secrets.opslimit: outside the range this host accepts.',
            'secrets.memlimit: outside the range this host accepts.',
            'secrets.salt: not base64.',
        ], $this->problems($document));
    }

    public function testDeepAndHugeInputIsRefused(): void
    {
        $this->expectException(InvalidExport::class);
        new ExportParser()->parse(str_repeat('[', 40).str_repeat(']', 40));
    }

    public function testReadFileRefusesWhatIsTooLarge(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'export-test');
        self::assertIsString($file);
        try {
            file_put_contents($file, str_repeat('x', ExportFormat::MAX_BYTES + 1));
            $this->expectException(InvalidExport::class);
            $this->expectExceptionMessage('larger than 2 MiB');
            ExportFormat::readFile($file);
        } finally {
            @unlink($file);
        }
    }

    public function testReadFileRefusesAMissingFile(): void
    {
        $this->expectException(InvalidExport::class);
        ExportFormat::readFile('/nonexistent/export.json');
    }
}
