<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Fleet\Upgrade\ReleaseCatalog;
use App\Message\RefreshReleaseCatalog;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshReleaseCatalogHandler
{
    public function __construct(private ReleaseCatalog $catalog)
    {
    }

    public function __invoke(RefreshReleaseCatalog $message): void
    {
        $this->catalog->refresh();
    }
}
