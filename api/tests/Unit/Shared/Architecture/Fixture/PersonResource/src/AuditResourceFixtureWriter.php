<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * The synthetic source tree the person-resource gate sweeps when it is driven over fixtures instead of the
 * real one. One writer of one type is all the derivation needs: with it, a fixture registry can hold a
 * classified type nothing writes (the graveyard the staleness check exists against) beside one that is
 * written, and the two outcomes are told apart by the rule rather than by the sweep being broken.
 *
 * Nothing executes it — the gate reads source as text.
 *
 * @internal test fixture
 */
final class AuditResourceFixtureWriter
{
    public function write(string $id): AuditResource
    {
        return AuditResource::of('FixtureResource', $id);
    }
}
