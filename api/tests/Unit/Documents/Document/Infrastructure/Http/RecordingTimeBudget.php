<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Override;

final class RecordingTimeBudget implements UploadTimeBudget
{
    /** @var list<int> */
    private array $armed = [];

    #[Override]
    public function arm(int $seconds): void
    {
        $this->armed[] = $seconds;
    }

    /**
     * Read through a method rather than the property: PHPStan types a property of a freshly built object by its
     * default, and that `[]` would outlive the calls that fill it from inside the response.
     *
     * @return list<int>
     */
    public function armed(): array
    {
        return $this->armed;
    }
}
