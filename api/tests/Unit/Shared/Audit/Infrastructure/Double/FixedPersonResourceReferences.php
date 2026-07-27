<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\PersonResourceReferences;
use Override;

/**
 * In-tree fake {@see PersonResourceReferences} returning a fixed id list — the executable spec of the
 * port's contract, which is deliberately narrow: it reports only references the trail has **not** yet
 * anonymised, so an already-erased subject never appears here at all.
 *
 * A named class rather than an anonymous one: PDepend (behind PHPMD) cannot parse `new readonly class`,
 * which is what the fixers rewrite an inline double into.
 *
 * @internal
 */
final readonly class FixedPersonResourceReferences implements PersonResourceReferences
{
    /**
     * @param list<string> $ids
     */
    public function __construct(private array $ids)
    {
    }

    #[Override]
    public function unerasedIdsOfType(string $resourceType): array
    {
        return $this->ids;
    }
}
