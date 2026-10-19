<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One authenticated request (it passed the 404 and signature checks): what arrived, the decision taken and what
 * happened. Requests rejected for a bad signature are not rows (unauthenticated callers must not grow the table); they
 * are a log warning. The project and source are plain columns: the log outlives a deleted endpoint.
 *
 * `dedupeId` is set only on a delivery that matched a rule; the unique index on (project, source, dedupe id) is what
 * makes a retried delivery a duplicate. The pruner clears it after seven days.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_delivery')]
#[ORM\Index(name: 'idx_webhook_delivery_project', columns: ['project_name', 'received_at'])]
#[ORM\Index(name: 'idx_webhook_delivery_received', columns: ['received_at'])]
#[ORM\UniqueConstraint(name: 'uniq_webhook_delivery_dedupe', columns: ['project_name', 'source', 'dedupe_id'])]
class WebhookDelivery
{
    public const string ACCEPTED = 'accepted';
    public const string IGNORED = 'ignored';
    public const string DUPLICATE = 'duplicate';
    public const string FAILED = 'failed';

    public const int FIELD_MAX = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $projectName;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: WebhookSource::class)]
    private WebhookSource $source;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $event;

    /** The delivery id the sender gave (Jira `X-Atlassian-Webhook-Identifier`, Bitbucket `X-Request-UUID`), cut to 64. */
    #[ORM\Column(type: Types::STRING, length: self::FIELD_MAX, nullable: true)]
    private ?string $deliveryId;

    #[ORM\Column(type: Types::STRING, length: self::FIELD_MAX, nullable: true)]
    private ?string $dedupeId = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $result;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $reason;

    /** What the event is about: the issue key, or the branch / pull request. */
    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $subject;

    /** The change, e.g. "To Do -> Dispatched". */
    #[ORM\Column(name: 'status_change', type: Types::STRING, length: 200, nullable: true)]
    private ?string $change;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $rule;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $action;

    /** The recorded decision, e.g. "would deploy fle-66 (template jira-work)". */
    #[ORM\Column(type: Types::STRING, length: 300, nullable: true)]
    private ?string $decision;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $jobId = null;

    public function __construct(
        \DateTimeImmutable $receivedAt,
        string $projectName,
        WebhookSource $source,
        string $event,
        ?string $deliveryId,
        string $result,
        ?string $reason = null,
        ?string $subject = null,
        ?string $change = null,
        ?WebhookRule $rule = null,
        ?string $decision = null,
    ) {
        $this->receivedAt = $receivedAt;
        $this->projectName = $projectName;
        $this->source = $source;
        $this->event = self::cut($event, 64) ?? '';
        $this->deliveryId = self::cut($deliveryId, self::FIELD_MAX);
        $this->result = $result;
        $this->reason = self::cut($reason, 200);
        $this->subject = self::cut($subject, 200);
        $this->change = self::cut($change, 200);
        $this->rule = null === $rule ? null : self::cut($rule->summary(), 200);
        $this->action = $rule?->action();
        $this->decision = self::cut($decision, 300);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function projectName(): string
    {
        return $this->projectName;
    }

    public function source(): WebhookSource
    {
        return $this->source;
    }

    public function event(): string
    {
        return $this->event;
    }

    public function deliveryId(): ?string
    {
        return $this->deliveryId;
    }

    public function dedupeId(): ?string
    {
        return $this->dedupeId;
    }

    public function result(): string
    {
        return $this->result;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function change(): ?string
    {
        return $this->change;
    }

    public function rule(): ?string
    {
        return $this->rule;
    }

    public function action(): ?string
    {
        return $this->action;
    }

    public function decision(): ?string
    {
        return $this->decision;
    }

    public function jobId(): ?int
    {
        return $this->jobId;
    }

    /**
     * Marks the delivery as the one that consumed its id.
     */
    public function consumeDedupeId(): void
    {
        $this->dedupeId = $this->deliveryId;
    }

    /**
     * What the action did: the note goes into the reason column (the decision stays as recorded).
     */
    public function recordAction(string $note, ?int $jobId): void
    {
        $this->reason = self::cut($note, 200);
        $this->jobId = $jobId;
    }

    private static function cut(?string $value, int $max): ?string
    {
        if (null === $value) {
            return null;
        }
        // The values come from an HTTP request: no control characters in a log line, and a bounded length.
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '?', $value) ?? '';

        return mb_strcut($clean, 0, $max, 'UTF-8');
    }
}
