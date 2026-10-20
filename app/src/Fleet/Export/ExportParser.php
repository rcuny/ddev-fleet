<?php

declare(strict_types=1);

namespace App\Fleet\Export;

use App\Domain\Project\GitBotMode;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateService;

/**
 * Turns the text of an export file into the canonical document the importer works with, or refuses it. It checks the
 * shape only (format, version, known keys, types, list sizes); the values go through the services' own rules in the
 * importer. A problem names a path such as `templates[1].post_deploy` and never repeats a value from the file.
 */
final class ExportParser
{
    public const int MAX_WEBHOOK_RULES = 100;
    public const int MAX_SCHEDULES = 200;
    public const int MAX_LINES = 500;

    /** @var list<string> */
    private array $problems = [];

    /**
     * @return array<string, mixed> the document with every optional key present (`secrets` is null or the sealed block)
     *
     * @throws InvalidExport
     */
    public function parse(string $text): array
    {
        if (\strlen($text) > ExportFormat::MAX_BYTES) {
            throw InvalidExport::of(\sprintf('The file is larger than %d MiB.', ExportFormat::MAX_BYTES / 1024 / 1024));
        }
        try {
            $data = json_decode($text, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidExport::of('The file is not valid JSON.');
        }
        if (!\is_array($data) || (array_is_list($data) && [] !== $data)) {
            throw InvalidExport::of('The file is not a project export.');
        }
        if (($data['format'] ?? null) !== ExportFormat::NAME) {
            throw InvalidExport::of(\sprintf('The file is not a project export (format must be "%s").', ExportFormat::NAME));
        }
        $version = $data['version'] ?? null;
        if (!\is_int($version)) {
            throw InvalidExport::of('The file has no integer version.');
        }
        if ($version > ExportFormat::VERSION) {
            throw InvalidExport::of(\sprintf('The file is version %d; this host reads version %d at most. Update this host first.', $version, ExportFormat::VERSION));
        }
        if (ExportFormat::VERSION !== $version) {
            throw InvalidExport::of(\sprintf('Version %d is not a known export version.', $version));
        }

        $this->problems = [];
        $this->keys($data, '', ['format', 'version', 'project', 'ports', 'templates', 'webhooks', 'schedules'], ['secrets']);
        $document = [
            'format' => ExportFormat::NAME,
            'version' => ExportFormat::VERSION,
            'project' => $this->project($data['project'] ?? null),
            'ports' => $this->ports($data['ports'] ?? null),
            'templates' => $this->templates($data['templates'] ?? null),
            'webhooks' => $this->webhooks($data['webhooks'] ?? null),
            'schedules' => $this->schedules($data['schedules'] ?? null),
            'secrets' => \array_key_exists('secrets', $data) ? $this->secrets($data['secrets']) : null,
        ];
        $this->crossChecks($document);

        if ([] !== $this->problems) {
            throw new InvalidExport($this->problems);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function project(mixed $value): array
    {
        $path = 'project';
        $keys = ['name', 'git', 'default_branch', 'default_template', 'additional_hostnames', 'ports', 'git_bot', 'issue_id_regexp', 'display_submodule_branch', 'typesense'];
        if (!$this->keys($value, $path, $keys)) {
            return [];
        }
        \assert(\is_array($value));
        $bot = ['mode' => GitBotMode::Inherit->value, 'name' => null, 'email' => null];
        if ($this->keys($value['git_bot'], 'project.git_bot', ['mode', 'name', 'email'])) {
            \assert(\is_array($value['git_bot']));
            $mode = $this->string($value['git_bot']['mode'], 'project.git_bot.mode');
            if (null !== $mode && null === GitBotMode::tryFrom($mode)) {
                $this->problems[] = 'project.git_bot.mode: unknown mode.';
            }
            $bot = [
                'mode' => $mode ?? GitBotMode::Inherit->value,
                'name' => $this->nullableString($value['git_bot']['name'], 'project.git_bot.name'),
                'email' => $this->nullableString($value['git_bot']['email'], 'project.git_bot.email'),
            ];
        }

        return [
            'name' => $this->string($value['name'], 'project.name') ?? '',
            'git' => $this->string($value['git'], 'project.git') ?? '',
            'default_branch' => $this->nullableString($value['default_branch'], 'project.default_branch'),
            'default_template' => $this->nullableString($value['default_template'], 'project.default_template'),
            'additional_hostnames' => $this->stringList($value['additional_hostnames'], 'project.additional_hostnames', ProjectService::MAX_HOSTNAMES),
            'ports' => $this->stringList($value['ports'], 'project.ports', ProjectService::MAX_PORTS),
            'git_bot' => $bot,
            'issue_id_regexp' => $this->nullableString($value['issue_id_regexp'], 'project.issue_id_regexp'),
            'display_submodule_branch' => $this->nullableString($value['display_submodule_branch'], 'project.display_submodule_branch'),
            'typesense' => $this->bool($value['typesense'], 'project.typesense'),
        ];
    }

    /**
     * @return list<array{name: string, public: int, router: int}>
     */
    private function ports(mixed $value): array
    {
        $ports = [];
        foreach ($this->items($value, 'ports', ProjectService::MAX_PORTS) as $i => $item) {
            $path = "ports[$i]";
            if (!$this->keys($item, $path, ['name', 'public', 'router'])) {
                continue;
            }
            \assert(\is_array($item));
            $ports[] = [
                'name' => $this->string($item['name'], "$path.name") ?? '',
                'public' => $this->int($item['public'], "$path.public"),
                'router' => $this->int($item['router'], "$path.router"),
            ];
        }

        return $ports;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(mixed $value): array
    {
        $templates = [];
        foreach ($this->items($value, 'templates', TemplateService::MAX_PER_PROJECT) as $i => $item) {
            $path = "templates[$i]";
            if (!$this->keys($item, $path, ['name', 'drupal_env', 'post_deploy'])) {
                continue;
            }
            \assert(\is_array($item));
            $commands = ['exec' => [], 'tty1' => [], 'tty2' => []];
            if ($this->keys($item['post_deploy'], "$path.post_deploy", ['exec', 'tty1', 'tty2'])) {
                \assert(\is_array($item['post_deploy']));
                foreach ($commands as $key => $_) {
                    $commands[$key] = $this->stringList($item['post_deploy'][$key], "$path.post_deploy.$key", self::MAX_LINES);
                }
            }
            $templates[] = [
                'name' => $this->string($item['name'], "$path.name") ?? '',
                'drupal_env' => $this->nullableString($item['drupal_env'], "$path.drupal_env"),
                'post_deploy' => $commands,
            ];
        }

        return $templates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function webhooks(mixed $value): array
    {
        $endpoints = [];
        foreach ($this->items($value, 'webhooks', \count(WebhookSource::cases())) as $i => $item) {
            $path = "webhooks[$i]";
            if (!$this->keys($item, $path, ['source', 'rules'])) {
                continue;
            }
            \assert(\is_array($item));
            $source = $this->string($item['source'], "$path.source");
            if (null !== $source && null === WebhookSource::tryFrom($source)) {
                $this->problems[] = "$path.source: unknown source.";
            }
            $rules = [];
            foreach ($this->items($item['rules'], "$path.rules", self::MAX_WEBHOOK_RULES) as $j => $rule) {
                $rulePath = "$path.rules[$j]";
                if (!$this->keys($rule, $rulePath, ['action', 'match', 'params'])) {
                    continue;
                }
                \assert(\is_array($rule));
                $rules[] = [
                    'action' => $this->string($rule['action'], "$rulePath.action") ?? '',
                    'match' => $this->stringMap($rule['match'], "$rulePath.match"),
                    'params' => $this->stringMap($rule['params'], "$rulePath.params"),
                ];
            }
            $endpoints[] = ['source' => $source ?? '', 'rules' => $rules];
        }

        return $endpoints;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schedules(mixed $value): array
    {
        $schedules = [];
        foreach ($this->items($value, 'schedules', self::MAX_SCHEDULES) as $i => $item) {
            $path = "schedules[$i]";
            if (!$this->keys($item, $path, ['name', 'cron', 'timezone', 'action', 'instance', 'enabled'])) {
                continue;
            }
            \assert(\is_array($item));
            $schedules[] = [
                'name' => $this->string($item['name'], "$path.name") ?? '',
                'cron' => $this->string($item['cron'], "$path.cron") ?? '',
                'timezone' => $this->string($item['timezone'], "$path.timezone") ?? '',
                'action' => $this->string($item['action'], "$path.action") ?? '',
                'instance' => $this->nullableString($item['instance'], "$path.instance"),
                'enabled' => $this->bool($item['enabled'], "$path.enabled"),
            ];
        }

        return $schedules;
    }

    /**
     * @return array{kdf: string, opslimit: int, memlimit: int, salt: string, nonce: string, ciphertext: string}|null
     */
    private function secrets(mixed $value): ?array
    {
        if (!$this->keys($value, 'secrets', ['kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext'])) {
            return null;
        }
        \assert(\is_array($value));
        $sealed = [
            'kdf' => $this->string($value['kdf'], 'secrets.kdf') ?? '',
            'opslimit' => $this->int($value['opslimit'], 'secrets.opslimit'),
            'memlimit' => $this->int($value['memlimit'], 'secrets.memlimit'),
            'salt' => $this->string($value['salt'], 'secrets.salt') ?? '',
            'nonce' => $this->string($value['nonce'], 'secrets.nonce') ?? '',
            'ciphertext' => $this->string($value['ciphertext'], 'secrets.ciphertext') ?? '',
        ];
        if (PassphraseSealer::KDF !== $sealed['kdf']) {
            $this->problems[] = 'secrets.kdf: unknown key derivation.';
        }
        if ($sealed['opslimit'] < PassphraseSealer::MIN_OPS || $sealed['opslimit'] > PassphraseSealer::MAX_OPS) {
            $this->problems[] = 'secrets.opslimit: outside the range this host accepts.';
        }
        if ($sealed['memlimit'] < PassphraseSealer::MIN_MEM || $sealed['memlimit'] > PassphraseSealer::MAX_MEM) {
            $this->problems[] = 'secrets.memlimit: outside the range this host accepts.';
        }
        foreach (['salt', 'nonce', 'ciphertext'] as $field) {
            if (false === base64_decode($sealed[$field], true)) {
                $this->problems[] = "secrets.$field: not base64.";
            }
        }

        return $sealed;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function crossChecks(array $document): void
    {
        $project = $document['project'];
        if ([] === $project) {
            return;
        }
        $portNames = array_column($document['ports'], 'name');
        $this->noDuplicates($portNames, 'ports');
        foreach ($project['ports'] as $i => $name) {
            if (!\in_array($name, $portNames, true)) {
                $this->problems[] = "project.ports[$i]: the port is not described in ports.";
            }
        }
        $this->noDuplicates(array_column($document['templates'], 'name'), 'templates');
        $this->noDuplicates(array_column($document['webhooks'], 'source'), 'webhooks');
        $this->noDuplicates(array_column($document['schedules'], 'name'), 'schedules');
    }

    /**
     * @param array<mixed> $names
     */
    private function noDuplicates(array $names, string $path): void
    {
        if (\count(array_unique($names)) !== \count($names)) {
            $this->problems[] = "$path: an entry appears twice.";
        }
    }

    /**
     * Checks that $value is an object (an empty one decodes to []) holding exactly the required keys and some of the
     * optional ones, and records a problem for each unknown or missing key.
     *
     * @param list<string> $required
     * @param list<string> $optional
     */
    private function keys(mixed $value, string $path, array $required, array $optional = []): bool
    {
        $where = '' === $path ? 'The file' : $path;
        if (!\is_array($value) || (array_is_list($value) && [] !== $value)) {
            $this->problems[] = "$where: expected an object.";

            return false;
        }
        $ok = true;
        foreach (array_diff(array_keys($value), $required, $optional) as $key) {
            $this->problems[] = ('' === $path ? '' : $path.'.').(\is_string($key) && 1 === preg_match('/^[a-z_]{1,40}$/D', $key) ? $key : '(key)').': unknown key.';
            $ok = false;
        }
        foreach ($required as $key) {
            if (!\array_key_exists($key, $value)) {
                $this->problems[] = ('' === $path ? '' : $path.'.')."$key: missing.";
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $value, string $path, int $max): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            $this->problems[] = "$path: expected a list.";

            return [];
        }
        if (\count($value) > $max) {
            $this->problems[] = \sprintf('%s: at most %d entries.', $path, $max);

            return [];
        }

        return $value;
    }

    private function string(mixed $value, string $path): ?string
    {
        if (!\is_string($value)) {
            $this->problems[] = "$path: expected a string.";

            return null;
        }
        if (\strlen($value) > 8192) {
            $this->problems[] = "$path: too long.";

            return null;
        }

        return $value;
    }

    private function nullableString(mixed $value, string $path): ?string
    {
        return null === $value ? null : $this->string($value, $path);
    }

    private function int(mixed $value, string $path): int
    {
        if (!\is_int($value)) {
            $this->problems[] = "$path: expected an integer.";

            return 0;
        }

        return $value;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (!\is_bool($value)) {
            $this->problems[] = "$path: expected true or false.";

            return false;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $path, int $max): array
    {
        $strings = [];
        foreach ($this->items($value, $path, $max) as $i => $item) {
            $string = $this->string($item, "{$path}[$i]");
            if (null !== $string) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value, string $path): array
    {
        if (!\is_array($value) || (array_is_list($value) && [] !== $value)) {
            $this->problems[] = "$path: expected an object.";

            return [];
        }
        if (\count($value) > 20) {
            $this->problems[] = "$path: at most 20 entries.";

            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $string = $this->string($item, $path.'.'.(1 === preg_match('/^[a-z_]{1,40}$/D', (string) $key) ? $key : '(key)'));
            if (null !== $string && \is_string($key)) {
                $map[$key] = $string;
            }
        }
        ksort($map);

        return $map;
    }
}
