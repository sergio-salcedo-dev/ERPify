<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Purger;

/**
 * What the purge did, in the order it did it. A shared object rather than a by-reference array: the
 * order between the inner purge, the truncate and the projector resets is the assertion, so it needs a
 * reader as well as a writer.
 */
final class PurgeCallLog
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $step): void
    {
        $this->entries[] = $step;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
