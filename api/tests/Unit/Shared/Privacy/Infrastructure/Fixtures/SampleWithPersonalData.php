<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Fixtures;

use Erpify\Shared\Privacy\Domain\PersonalData;

final class SampleWithPersonalData
{
    public function __construct(
        #[PersonalData]
        public string $fullName = '',
        #[PersonalData]
        public string $email = '',
        public string $reference = '',
    ) {
    }
}
