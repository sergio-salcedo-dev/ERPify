<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * A SECOND file building the same resource type as {@see AuditResourceFixtureWriter}.
 *
 * It exists so the single-writer rule over the real tree is falsifiable. There, exactly one file derives the
 * person-denoting type — the erasure that also removes it — and an assertion pinning that is indistinguishable
 * from one that can never see a second file. This fixture makes the sweep return two, which is the state the
 * real-tree rule refuses.
 *
 * Nothing executes it; the gate reads source as text.
 *
 * @internal test fixture
 */
final class SecondAuditResourceFixtureWriter
{
    public function write(string $id): AuditResource
    {
        return AuditResource::of('FixtureResource', $id);
    }
}
