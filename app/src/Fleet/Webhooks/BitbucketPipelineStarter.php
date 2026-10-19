<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Starts a custom pipeline of a Bitbucket Cloud repository: `POST /2.0/repositories/<workspace>/<slug>/pipelines/` with
 * a `pipeline:write` bearer token. The token goes into the Authorization header only: it is not in the URL, the body, a
 * log line or an exception message.
 */
final readonly class BitbucketPipelineStarter
{
    public const string API = 'https://api.bitbucket.org/2.0';
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(private HttpClientInterface $client)
    {
    }

    /**
     * @param string $repo `workspace/slug`
     *
     * @throws PipelineStartFailed
     */
    public function start(string $repo, string $pattern, string $ref, #[\SensitiveParameter] string $token): void
    {
        $url = self::API.'/repositories/'.implode('/', array_map('rawurlencode', explode('/', $repo))).'/pipelines/';

        try {
            $status = $this->client->request('POST', $url, [
                'auth_bearer' => $token,
                'headers' => ['Accept' => 'application/json'],
                'json' => ['target' => [
                    'type' => 'pipeline_ref_target',
                    'ref_type' => 'branch',
                    'ref_name' => $ref,
                    'selector' => ['type' => 'custom', 'pattern' => $pattern],
                ]],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new PipelineStartFailed(\sprintf('Bitbucket request failed (%s)', new \ReflectionClass($e)->getShortName()));
        }

        if ($status < 200 || $status >= 300) {
            throw new PipelineStartFailed(\sprintf('Bitbucket answered HTTP %d', $status));
        }
    }
}
