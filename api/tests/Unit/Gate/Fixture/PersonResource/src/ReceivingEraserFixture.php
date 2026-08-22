<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Application\AuditResourceAnonymiser;
use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * An erasure owner that satisfies the wiring rule and derives nothing: it holds the anonymiser, calls it, and
 * names the type — but the resource it erases was built by somebody else and arrives as a parameter.
 *
 * That combination is the one the wiring rule cannot see. A `person` line pointing here would read green on
 * every earlier check while the file that actually writes the identifier stayed anonymous, which is the split
 * this registry exists to refuse: whoever persists a person's id has to be the one named as erasing it. No
 * file in the real tree takes this shape, so without the fixture the rule that forbids it would be asserted
 * by nothing.
 *
 * Nothing executes it — the rules read source as text.
 *
 * @internal test fixture
 */
final readonly class ReceivingEraserFixture
{
    public function __construct(private AuditResourceAnonymiser $auditResourceAnonymiser)
    {
    }

    public function erase(AuditResource $resource, string $pseudonym): int
    {
        return $this->auditResourceAnonymiser->anonymise($resource, $pseudonym);
    }

    public function type(): string
    {
        return 'FixtureResource';
    }
}
