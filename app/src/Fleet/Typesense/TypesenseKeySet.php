<?php

declare(strict_types=1);

namespace App\Fleet\Typesense;

final readonly class TypesenseKeySet
{
    public function __construct(
        #[\SensitiveParameter]
        public string $admin,
        #[\SensitiveParameter]
        public string $search,
    ) {
    }
}
