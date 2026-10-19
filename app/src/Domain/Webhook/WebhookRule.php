<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "When this event arrives, do that." `match` holds the conditions (Jira: `on_status`; Bitbucket: `on_event`, `repo`,
 * `branch`, `state`, `comment`) and `params` what the action needs (`template`, `branch`, `label`, `pattern`, `ref`),
 * both under the 0.x key names. RuleMatcher reads them; WebhookRuleService validates them on the way in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_rule')]
class WebhookRule
{
    public const string ACTION_DEPLOY = 'deploy';
    public const string ACTION_RUN_PIPELINE = 'run-pipeline';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class, inversedBy: 'rules')]
    #[ORM\JoinColumn(name: 'endpoint_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WebhookEndpoint $endpoint;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $action;

    /** @var array<string, string> */
    #[ORM\Column(name: 'match_conditions', type: Types::JSON)]
    private array $match;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $params;

    /**
     * @param array<string, string> $match
     * @param array<string, string> $params
     */
    public function __construct(WebhookEndpoint $endpoint, int $position, string $action, array $match, array $params)
    {
        $this->endpoint = $endpoint;
        $this->position = $position;
        $this->action = $action;
        $this->match = $match;
        $this->params = $params;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function endpoint(): WebhookEndpoint
    {
        return $this->endpoint;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, string>
     */
    public function match(): array
    {
        return $this->match;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * A short selector for lists and the delivery log, such as `on_status=Dispatched`.
     */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->match as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode(' ', $parts);
    }
}
