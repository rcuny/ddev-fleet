<?php

declare(strict_types=1);

namespace App\Domain\ApiCall;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The evidence of one terminal input call (FLE-286): which token sent what to which pane, with the text (up to 4 KiB, the
 * audit log would cut it). The row is written BEFORE the keys are sent (`pending`) and finished afterwards (`sent` or
 * `failed`), so a call that cannot be recorded is never carried out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_call')]
#[ORM\Index(name: 'idx_api_call_token', columns: ['token_id', 'created_at'])]
#[ORM\Index(name: 'idx_api_call_created', columns: ['created_at'])]
class ApiCall
{
    public const string PENDING = 'pending';
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    public const int TEXT_MAX = 4096;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $outcome = self::PENDING;

    public function __construct(
        #[ORM\Column(type: Types::INTEGER)]
        private int $tokenId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $actor,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $route,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $instance,
        #[ORM\Column(type: Types::STRING, length: 8)]
        private string $pane,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $foreground,
        #[ORM\Column(type: Types::STRING, length: 45)]
        private string $clientIp,
        #[ORM\Column(type: Types::INTEGER)]
        private int $length,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $sha256,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column(type: Types::BOOLEAN)]
        private bool $enter,
        #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->outcome = self::PENDING;
        $this->actor = mb_substr($actor, 0, 64);
        $this->foreground = mb_substr($foreground, 0, 64);
        $this->text = mb_strcut($text, 0, self::TEXT_MAX);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function actor(): string
    {
        return $this->actor;
    }

    public function tokenId(): int
    {
        return $this->tokenId;
    }

    public function instance(): string
    {
        return $this->instance;
    }

    public function pane(): string
    {
        return $this->pane;
    }

    public function foreground(): string
    {
        return $this->foreground;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function enter(): bool
    {
        return $this->enter;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function finish(string $outcome): void
    {
        $this->outcome = $outcome;
    }
}
