<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Http\ClientAddress;
use App\Api\Terminal\TerminalText;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Instance\InstanceRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Workspace\Pane;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use App\Security\Api\ApiAuthorizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/instances/{id}/terminal?pane=tty2&lines=40` (scope `terminal:read`): a read-only capture of tty1 or tty2 of
 * an instance's tmux window. The token must list the instance's project and an instance pattern that matches the id;
 * the sidebar and the `general` window are not addressable at all. The text is escape-free, masked, capped (200 lines,
 * 64 KiB), the call has its own rate limit and each read is audited (counts, never the text).
 */
final readonly class TerminalController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private InstanceRepository $instances,
        private Tmux $tmux,
        private TerminalText $cleaner,
        private AuditLog $audit,
        #[Autowire(service: 'limiter.api_terminal_read')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/v1/instances/{id}/terminal', name: 'api_v1_instance_terminal', methods: ['GET'], requirements: ['id' => '[^/:]+'])]
    public function read(string $id, Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::TerminalRead);
        $instance = $this->instances->find($id);
        if (null === $instance) {
            throw ApiProblem::notFound('No such instance.');
        }
        $this->authorizer->require(ApiScope::TerminalRead, $instance->project()->name());
        if (!$token->allowsInstance($id)) {
            $this->audit->record($token->getUserIdentifier(), AuditLog::API_DENIED, \sprintf('instance_not_allowed:%s instance=%s ip=%s', ApiScope::TerminalRead->value, $id, ClientAddress::of($request)));

            throw ApiProblem::instanceNotAllowed($id);
        }

        $query = $request->query->all();
        $pane = match ($query['pane'] ?? 'tty2') {
            'tty1' => Pane::Tty1,
            'tty2' => Pane::Tty2,
            default => throw ApiProblem::invalid('invalid_pane', '"pane" must be tty1 or tty2.'),
        };
        $lines = $query['lines'] ?? (string) TerminalText::DEFAULT_LINES;
        if (!\is_string($lines) || 1 !== preg_match('/^[0-9]{1,4}$/D', $lines) || (int) $lines < 1 || (int) $lines > TerminalText::MAX_LINES) {
            throw ApiProblem::invalid('invalid_lines', \sprintf('"lines" must be an integer from 1 to %d.', TerminalText::MAX_LINES));
        }

        $limit = $this->limiter->create((string) $token->tokenId())->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblem::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        try {
            $raw = $this->tmux->capture($id, $pane, (int) $lines);
        } catch (WorkspaceException) {
            throw new ApiProblem(503, 'terminal_unavailable', 'The terminal could not be read.');
        }
        if (null === $raw) {
            throw ApiProblem::notFound('That instance has no such terminal pane.');
        }
        $clean = $this->cleaner->clean($raw);
        $this->audit->record(
            $token->getUserIdentifier(),
            AuditLog::API_TERMINAL_READ,
            \sprintf('%s %s lines=%d bytes=%d ip=%s', $id, $pane->name, (int) $lines, \strlen($clean['text']), ClientAddress::of($request)),
        );

        return new JsonResponse([
            'instance' => $id,
            'pane' => 'tty'.$pane->value,
            'lines' => (int) $lines,
            'truncated' => $clean['truncated'],
            'captured_at' => new \DateTimeImmutable()->format(\DATE_ATOM),
            'text' => $clean['text'],
        ]);
    }
}
