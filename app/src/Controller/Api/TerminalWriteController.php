<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use App\Api\Http\ClientAddress;
use App\Api\Terminal\EvidenceMasker;
use App\Api\Terminal\TerminalInput;
use App\Api\Terminal\TerminalWriteNotifier;
use App\Api\Token\ApiTokenPolicy;
use App\Api\Write\JsonBody;
use App\Domain\ApiCall\ApiCall;
use App\Domain\ApiCall\ApiCallRepository;
use App\Domain\ApiToken\ApiScope;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Workspace\Pane;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use App\Security\Api\ApiAuthorizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/v1/instances/{id}/terminal` (scope `terminal:write`, FLE-286): types one line of text into the agent pane (tty1 or
 * tty2) of an instance. The most dangerous call of the API, so every gate is checked in this order and each one stops it:
 * scope, the project in the token, the instance pattern in the token, the project's opt-in (off by default, admin only),
 * a running instance, the text allow-list, two rate limits, then the pane itself (found strictly, in the instance's own window,
 * foreground command must be the agent, never a shell). The evidence (`api_call`, with the text) and the audit entry are written
 * BEFORE the keys are sent: when either cannot be written the call fails with 503 and nothing is typed.
 */
final readonly class TerminalWriteController
{
    public function __construct(
        private ApiAuthorizer $authorizer,
        private InstanceRepository $instances,
        private Tmux $tmux,
        private AuditLog $audit,
        private ApiCallRepository $calls,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.api_terminal_write')]
        private RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.api_terminal_write_burst')]
        private RateLimiterFactoryInterface $burst,
        #[Autowire(service: 'limiter.api_terminal_write_instance')]
        private RateLimiterFactoryInterface $instanceLimiter,
        #[Autowire(service: 'limiter.api_terminal_write_denied')]
        private RateLimiterFactoryInterface $denied,
        private EvidenceMasker $masker,
        private TerminalWriteNotifier $notifier,
    ) {
    }

    #[Route('/api/v1/instances/{id}/terminal', name: 'api_v1_instance_terminal_write', methods: ['POST'], requirements: ['id' => '[^/:]+'])]
    public function write(string $id, Request $request): JsonResponse
    {
        $token = $this->authorizer->require(ApiScope::TerminalWrite);
        $ip = ClientAddress::of($request);
        // The policy again, on the stored token: a row edited after issue must not keep a working terminal token.
        if (null !== $why = ApiTokenPolicy::terminalWriteProblem($token->token(), $ip)) {
            $this->deny($token->tokenId(), $token->getUserIdentifier(), 'token_policy_'.$why, $id, $ip);

            throw new ApiProblem(403, 'token_not_permitted', 'This token may not send terminal input.');
        }
        // A missing instance, another project's and one outside the token's patterns all answer the same.
        $instance = $this->instances->find($id);
        if (null === $instance || !$token->allowsProject($instance->project()->name()) || !$token->allowsInstance($id)) {
            $this->deny($token->tokenId(), $token->getUserIdentifier(), null === $instance ? 'no_such_instance' : 'instance_not_allowed', $id, $ip);

            throw ApiProblem::notFound('No such instance.');
        }
        $project = $instance->project();
        if (!$project->allowApiTerminal()) {
            $this->deny($token->tokenId(), $token->getUserIdentifier(), 'terminal_write_disabled', $id, $ip);

            throw ApiProblem::conflict('terminal_write_disabled', 'Terminal input over the API is not enabled for this project. An administrator turns it on in the project settings.');
        }
        if (InstanceState::Running !== $instance->state()) {
            throw ApiProblem::conflict('instance_not_running', 'The instance is not running.');
        }

        $body = JsonBody::of($request);
        $paneName = $body->string('pane') ?? 'tty2';
        $pane = match ($paneName) {
            'tty1' => Pane::Tty1,
            'tty2' => Pane::Tty2,
            default => throw ApiProblem::invalid('invalid_pane', '"pane" must be tty1 or tty2.'),
        };
        $text = $body->string('text', true);
        \assert(null !== $text);
        $enter = $body->bool('enter');
        if (null !== $problem = TerminalInput::problem($text)) {
            $this->deny($token->tokenId(), $token->getUserIdentifier(), 'invalid_text', $id, $ip);

            throw ApiProblem::validation(['text' => $problem]);
        }

        $key = (string) $token->tokenId();
        foreach ([[$this->burst, $key], [$this->limiter, $key], [$this->instanceLimiter, $id]] as [$factory, $limitKey]) {
            $limit = $factory->create($limitKey)->consume();
            if (!$limit->isAccepted()) {
                $this->deny($token->tokenId(), $token->getUserIdentifier(), 'rate_limited', $id, $ip);

                throw ApiProblem::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            }
        }

        try {
            $target = $this->tmux->agentPane($id, $pane);
        } catch (WorkspaceException) {
            throw new ApiProblem(503, 'terminal_unavailable', 'The terminal could not be reached.');
        }
        if (null === $target) {
            throw ApiProblem::notFound('That instance has no such terminal pane.');
        }
        if (!$target->isAgent()) {
            $this->deny($token->tokenId(), $token->getUserIdentifier(), 'pane_not_agent', $id.' '.$paneName.' foreground='.self::safe($target->command), $ip);

            throw ApiProblem::conflict('pane_not_agent', 'The pane is not running the agent; terminal input is only sent to an agent, never to a shell.');
        }

        // Evidence and audit first: no record, no keys.
        $call = new ApiCall($token->tokenId(), $token->getUserIdentifier(), 'POST terminal', $id, $paneName, $target->command, $ip, \strlen($text), hash('sha256', $text), $this->masker->mask($text), $enter, new \DateTimeImmutable());
        try {
            $this->calls->save($call);
            $this->audit->record(
                $token->getUserIdentifier(),
                AuditLog::API_TERMINAL_WRITE,
                \sprintf('%s %s foreground=%s bytes=%d enter=%s call=%s ip=%s', $id, $paneName, self::safe($target->command), \strlen($text), $enter ? 'yes' : 'no', (string) $call->id(), $ip),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Terminal input refused: the evidence could not be written.', ['exception' => $e::class]);

            throw new ApiProblem(503, 'audit_unavailable', 'The call could not be recorded, so nothing was sent.');
        }

        try {
            $this->tmux->sendToAgent($id, $target, $text, $enter);
        } catch (WorkspaceException) {
            $this->finish($call, ApiCall::FAILED);
            // Keys may already have been typed before the send gave up: the owner is told all the same.
            $this->notifier->written($call);

            throw ApiProblem::conflict('pane_changed', 'The pane stopped running the agent before the call completed; Enter was not sent.');
        }
        $this->finish($call, ApiCall::SENT);
        $this->notifier->written($call);

        return new JsonResponse([
            'instance' => $id,
            'pane' => $paneName,
            'foreground' => $target->command,
            'length' => \strlen($text),
            'sha256' => hash('sha256', $text),
            'enter' => $enter,
            'call' => $call->id(),
        ]);
    }

    private function finish(ApiCall $call, string $outcome): void
    {
        try {
            $call->finish($outcome);
            $this->calls->save($call);
        } catch (\Throwable $e) {
            $this->logger->error('A terminal call outcome could not be stored.', ['exception' => $e::class]);
        }
    }

    /** Audits a refusal, unless this token already filled its refusal quota (then it is refused without a row, so it cannot flood the table). */
    private function deny(int $tokenId, string $actor, string $reason, string $what, string $ip): void
    {
        if (!$this->denied->create((string) $tokenId)->consume()->isAccepted()) {
            return;
        }
        $this->audit->record($actor, AuditLog::API_DENIED, \sprintf('%s:%s %s ip=%s', $reason, ApiScope::TerminalWrite->value, $what, $ip));
    }

    /** A foreground command name as tmux prints it, reduced to harmless characters for a log line. */
    private static function safe(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '?', $value), 0, 40);
    }
}
