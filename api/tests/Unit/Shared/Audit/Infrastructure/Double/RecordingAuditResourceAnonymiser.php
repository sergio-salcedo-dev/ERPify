<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * Spy {@see AuditResourceAnonymiser}: reports a fixed match count and records every call as
 * `[type, id, pseudonym]`, so a test can assert not only that the resource axis was erased but that it was
 * handed the **actor pass's** pseudonym — the whole point of the two-call sequence, and the one thing a
 * caller can silently get wrong by minting a second one.
 *
 * Its match count is deliberately independent of {@see RecordingAuditActorAnonymiser}'s: the two axes are
 * different row sets, and a test that gives them the same number cannot catch a sum where two separate
 * counts were required.
 *
 * @internal
 */
final class RecordingAuditResourceAnonymiser implements AuditResourceAnonymiser
{
    /** @var list<array{type: string, id: string, pseudonym: string}> */
    public array $calls = [];

    /**
     * The resource VALUES as received, kept beside the flattened calls so a test can assert that the object
     * the caller erased is the very one it logged — not merely one that compares equal. That distinction is
     * the design: when the write and the erasure share a single value, naming different resources stops
     * being expressible, and a test that only compared type and id would still pass if they were rebuilt
     * apart and later drifted.
     *
     * @var list<AuditResource>
     */
    public array $resources = [];

    public function __construct(
        private readonly int $matchCount,
    ) {
    }

    #[Override]
    public function anonymise(AuditResource $resource, string $pseudonym): int
    {
        $this->calls[] = ['type' => $resource->type, 'id' => $resource->id, 'pseudonym' => $pseudonym];
        $this->resources[] = $resource;

        return $this->matchCount;
    }
}
