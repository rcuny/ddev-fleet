<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

final readonly class WebhookActionResult
{
    /**
     * @param bool        $performed whether the action really ran
     * @param string      $note      why it did not, or a short outcome; shown in the delivery log
     * @param int|null    $jobId     the job the action started, if any
     * @param string|null $instance  the id of the instance the job works on, if any
     */
    public function __construct(
        public bool $performed,
        public string $note,
        public ?int $jobId = null,
        public ?string $instance = null,
    ) {
    }
}
