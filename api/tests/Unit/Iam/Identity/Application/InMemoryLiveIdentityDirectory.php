<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Override;

/**
 * In-memory {@see LiveIdentityDirectory} over a fixed set of live ids. An empty set means every reference
 * handed to it names an identity that is gone, which is the state a reconciliation test starts from.
 *
 * @internal
 */
final readonly class InMemoryLiveIdentityDirectory implements LiveIdentityDirectory
{
    /**
     * @param list<string> $liveIds
     */
    public function __construct(private array $liveIds = [])
    {
    }

    /**
     * @param string[] $ids
     */
    #[Override]
    public function existingIdsAmong(array $ids): array
    {
        return \array_values(\array_filter(
            $ids,
            fn (string $id): bool => \in_array($id, $this->liveIds, true),
        ));
    }
}
