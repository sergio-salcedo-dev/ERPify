<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Override;

final class RecordingTimeBudget implements UploadTimeBudget
{
    /** @var list<int> */
    public array $armed = [];

    #[Override]
    public function arm(int $seconds): void
    {
        $this->armed[] = $seconds;
    }
}
