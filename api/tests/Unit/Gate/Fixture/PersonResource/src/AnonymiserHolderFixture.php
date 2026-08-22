<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;

/**
 * An erasure owner that holds the anonymiser, names the type, and never anonymises anything.
 *
 * It exists because that red has no counterpart in the real tree — every file holding an
 * `AuditResourceAnonymiser` also calls it — so without this fixture the middle of the wiring rule would be
 * asserted by nothing, and replacing the whole rule with `return null;` would leave the gate green. That is
 * the shape of defect the registry exists to make impossible, reproduced inside the control that enforces it.
 *
 * Nothing executes it — the rule reads source as text.
 *
 * @internal test fixture
 */
final readonly class AnonymiserHolderFixture
{
    public function __construct(private AuditResourceAnonymiser $auditResourceAnonymiser)
    {
    }

    public function anonymiser(): AuditResourceAnonymiser
    {
        return $this->auditResourceAnonymiser;
    }

    public function type(): string
    {
        return 'FixtureResource';
    }
}
