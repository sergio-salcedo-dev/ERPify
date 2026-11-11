<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

final readonly class PlantedIntent
{
    public function __construct(
        public string $id,
        public string $scopeId,
    ) {
    }
}
