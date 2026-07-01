<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Fixtures;

final class SampleWithoutPersonalData
{
    public function __construct(
        public string $reference = '',
        public string $label = '',
    ) {
    }
}
