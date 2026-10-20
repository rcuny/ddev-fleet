<?php

declare(strict_types=1);

namespace App\Fleet\Typesense;

use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Secrets\SecretOpenFailed;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Once ddev is up, for a Typesense project: registers the search-only key in the instance's Typesense through its admin
 * API, like 0.x `register_search_key`. The request goes to the ddev router on loopback (its Typesense entrypoint) with
 * the instance's host name in the Host header. A key with the description `fleet-search-only` is left alone, so a
 * redeploy adds nothing. Never fails a deploy: a problem is a WARNING line, and a redeploy tries again. The admin key
 * travels in a header only, and the job log masks it.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 150])]
final class TypesenseSearchKeyStep implements DeployStep
{
    public const string DESCRIPTION = 'fleet-search-only';

    private const float TIMEOUT = 10.0;

    public function __construct(
        private readonly TypesenseKeys $keys,
        private readonly TypesenseEnvironment $environment,
        private readonly InstanceHostnames $hostnames,
        private readonly HttpClientInterface $http,
        private readonly int $attempts = 3,
        private readonly int $retryDelaySeconds = 3,
    ) {
    }

    public function phase(): StepPhase
    {
        return StepPhase::AfterStart;
    }

    public function run(DeployContext $context): void
    {
        if (!$context->project->typesense()) {
            return;
        }

        try {
            $keys = $this->keys->ensure($context->project);
        } catch (SecretOpenFailed) {
            $context->log->line('WARNING: typesense: a stored Typesense key could not be opened; the search key was not registered.');

            return;
        }

        $base = 'http://127.0.0.1:'.$this->environment->routerPort().'/keys';
        $headers = [
            'Host' => $this->hostnames->primary($context->instance->id()),
            'X-TYPESENSE-API-KEY' => $keys->admin,
        ];

        $last = '';
        for ($attempt = 1; $attempt <= $this->attempts; ++$attempt) {
            try {
                if ($this->registered($base, $headers, $keys->search)) {
                    $context->log->line('typesense: search-only key registered');
                } else {
                    $context->log->line('typesense: search-only key already registered');
                }

                return;
            } catch (\Throwable $e) {
                $last = $e->getMessage();
                if ($attempt < $this->attempts && $this->retryDelaySeconds > 0) {
                    sleep($this->retryDelaySeconds);
                }
            }
        }
        $context->log->line('WARNING: typesense: the search-only key was not registered ('.$last.'); a redeploy tries again.');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return bool whether the key was created now (false: it was there already)
     */
    private function registered(string $url, array $headers, #[\SensitiveParameter] string $searchKey): bool
    {
        $list = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => self::TIMEOUT]);
        $body = $list->toArray();
        $existing = $body['keys'] ?? [];
        if (!\is_array($existing)) {
            throw new \RuntimeException('unexpected answer from the Typesense keys endpoint');
        }
        foreach ($existing as $key) {
            if (\is_array($key) && self::DESCRIPTION === ($key['description'] ?? null)) {
                return false;
            }
        }

        $this->http->request('POST', $url, [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
            'json' => [
                'value' => $searchKey,
                'description' => self::DESCRIPTION,
                'actions' => ['documents:search'],
                'collections' => ['*'],
            ],
        ])->getContent();

        return true;
    }
}
