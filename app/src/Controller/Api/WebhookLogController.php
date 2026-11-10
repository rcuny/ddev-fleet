<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Read\Cursor;
use App\Api\Read\ProjectVisibility;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/webhooks/log` (scope `read`, limited to the token's projects): recent deliveries with their subject,
 * rule and outcome. Filters `project`, `result`, `hide_ignored=1`. No body, no secret, no delivery signature.
 */
final readonly class WebhookLogController
{
    private const array RESULTS = [WebhookDelivery::ACCEPTED, WebhookDelivery::IGNORED, WebhookDelivery::DUPLICATE, WebhookDelivery::FAILED];

    public function __construct(
        private ApiAuthorizer $authorizer,
        private WebhookRepository $webhooks,
        private ProjectVisibility $visibility,
    ) {
    }

    #[Route('/api/v1/webhooks/log', name: 'api_v1_webhooks_log', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::Read);
        $cursor = Cursor::fromRequest($request);
        $project = $request->query->get('project');
        $project = \is_string($project) && '' !== $project ? $project : null;
        $result = $request->query->get('result');
        $result = \is_string($result) && '' !== $result ? $result : null;
        if (null !== $result && !\in_array($result, self::RESULTS, true)) {
            throw ApiProblem::invalid('invalid_filter', '"result" must be one of: '.implode(', ', self::RESULTS).'.');
        }
        $hideIgnored = '1' === $request->query->get('hide_ignored');
        $want = $cursor->limit + 1;
        $end = $cursor->position + $want;

        if (null !== $project) {
            $this->authorizer->require(ApiScope::Read, $project);
            $rows = $this->webhooks->deliveries($project, $hideIgnored, $want, $cursor->position, null, $result);
        } elseif ($token->unrestricted()) {
            $rows = $this->webhooks->deliveries(null, $hideIgnored, $want, $cursor->position, null, $result);
        } else {
            // A limited token: merge its projects' deliveries (newest first), then take the page.
            $rows = [];
            foreach ($this->visibility->names($token) as $name) {
                array_push($rows, ...$this->webhooks->deliveries($name, $hideIgnored, $end, 0, null, $result));
            }
            usort($rows, static fn (WebhookDelivery $a, WebhookDelivery $b): int => [$b->receivedAt(), $b->id()] <=> [$a->receivedAt(), $a->id()]);
            $rows = \array_slice($rows, $cursor->position, $want);
        }

        $more = \count($rows) > $cursor->limit;
        $rows = \array_slice($rows, 0, $cursor->limit);

        return new JsonResponse([
            'deliveries' => array_map(self::row(...), $rows),
            'next_cursor' => $more ? Cursor::encode($cursor->position + \count($rows)) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id(),
            'received_at' => $d->receivedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'project' => $d->projectName(),
            'source' => $d->source()->value,
            'event' => $d->event(),
            'delivery_id' => $d->deliveryId(),
            'subject' => $d->subject(),
            'change' => $d->change(),
            'result' => $d->result(),
            'reason' => $d->reason(),
            'rule' => $d->rule(),
            'action' => $d->action(),
            'decision' => $d->decision(),
            'job_id' => $d->jobId(),
        ];
    }
}
