<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SortDirection::class)]
final class SortDirectionTest extends TestCase
{
    public function testWireTokensAreTheUpperCaseBackingValues(): void
    {
        // Runtime-derived pin (array_map over cases() widens the type, so PHPStan does not
        // constant-fold the comparison the way it would on a direct ::ASC->value literal).
        // The wire contract is the uppercase backing value — `direction=ASC|DESC` — which is
        // deliberately distinct from the lowercase filter operators and rejected at mapping
        // when it falls outside the enum.
        $this->assertSame(['ASC', 'DESC'], \array_map(
            static fn (SortDirection $direction): string => $direction->value,
            SortDirection::cases(),
        ));
    }
}
