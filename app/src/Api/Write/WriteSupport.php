<?php

declare(strict_types=1);

namespace App\Api\Write;

use App\Api\Http\ApiProblem;
use App\Api\Http\ClientAddress;
use App\Domain\Job\Job;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Security\Api\ApiTokenUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every write endpoint shares: domain errors become problems, an accepted job becomes `202` with a `Location`,
 * and each accepted write is audited with the token, the target and the client address.
 */
final readonly class WriteSupport
{
    public function __construct(private AuditLog $audit, private RequestStack $requests)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationFailed $e) {
            throw ApiProblem::validation($e->errors);
        } catch (NotFound) {
            throw ApiProblem::notFound();
        } catch (Refused $e) {
            throw ApiProblem::conflict(Refused::BUSY === $e->reason ? 'instance_busy' : 'refused', $e->getMessage());
        }
    }

    public function accepted(Job $job): JsonResponse
    {
        $url = '/api/v1/jobs/'.$job->id();

        return new JsonResponse(['job' => self::jobRef($job)], Response::HTTP_ACCEPTED, ['Location' => $url]);
    }

    /**
     * @return array{id: int|null, state: string, url: string}
     */
    public static function jobRef(Job $job): array
    {
        return ['id' => $job->id(), 'state' => $job->status()->value, 'url' => '/api/v1/jobs/'.$job->id()];
    }

    public function audit(ApiTokenUser $token, string $what, string $target, Job $job): void
    {
        $request = $this->requests->getCurrentRequest();
        $this->audit->record(
            $token->getUserIdentifier(),
            AuditLog::API_WRITE,
            \sprintf('%s %s job=%s ip=%s', $what, $target, (string) $job->id(), $request instanceof Request ? ClientAddress::of($request) : 'unknown'),
        );
    }
}
