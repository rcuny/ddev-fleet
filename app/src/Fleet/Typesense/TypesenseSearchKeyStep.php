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
 * redeploy adds nothing. A cold Typesense container needs a while after `ddev start`, so a failed attempt is retried
 * with a doubling delay (capped) until MAX_WAIT_SECONDS have passed. Never fails a deploy: giving up is a WARNING line,
 * and a redeploy tries again. The admin key travels in a header only, and the job log masks it.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 150])]
final class TypesenseSearchKeyStep implements DeployStep
{
    public const string DESCRIPTION = 'fleet-search-only';

    private const float TIMEOUT = 10.0;

    /** How long after the first attempt the step keeps retrying before it gives up with a warning. */
    public const float MAX_WAIT_SECONDS = 60.0;

    private const float FIRST_DELAY_SECONDS = 1.0;

    private const float MAX_DELAY_SECONDS = 8.0;

    /** @var \Closure(): float */
    private \Closure $now;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(): float)|null     $now   the clock in seconds, injectable for tests
     * @param (\Closure(float): void)|null $sleep waits the given seconds, injectable for tests
     */
    public function __construct(
        private readonly TypesenseKeys $keys,
        private readonly TypesenseEnvironment $environment,
        private readonly InstanceHostnames $hostnames,
        private readonly HttpClientInterface $http,
        private readonly float $maxWaitSeconds = self::MAX_WAIT_SECONDS,
        ?\Closure $now = null,
        ?\Closure $sleep = null,
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
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
        $deadline = ($this->now)() + $this->maxWaitSeconds;
        $delay = self::FIRST_DELAY_SECONDS;
        while (true) {
            try {
                if ($this->registered($base, $headers, $keys->search)) {
                    $context->log->line('typesense: search-only key registered');
                } else {
                    $context->log->line('typesense: search-only key already registered');
                }

                return;
            } catch (\Throwable $e) {
                $last = $e->getMessage();
                if (($this->now)() + $delay > $deadline) {
                    break;
                }
                ($this->sleep)($delay);
                $delay = min($delay * 2, self::MAX_DELAY_SECONDS);
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
