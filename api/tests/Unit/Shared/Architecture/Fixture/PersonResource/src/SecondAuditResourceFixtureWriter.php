<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * A SECOND file building the same resource type as {@see AuditResourceFixtureWriter}.
 *
 * It exists so the derivation sweep is falsifiable in the direction that matters. A sweep that could only
 * ever return one file would satisfy every caller without proving it can SEE a second, and the whole registry
 * rests on it seeing every writer: an unseen one means no type in the universe, so no line demanded, so nobody
 * named as obliged to erase it. This fixture makes the sweep return two.
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
