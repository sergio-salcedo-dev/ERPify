<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference;

use Doctrine\ORM\Mapping as ORM;

/**
 * The concrete entity whose table carries {@see AbstractFixtureColumnCarrier}'s private column. It declares
 * no property of its own on purpose: everything it contributes to the derived universe arrives through
 * inheritance, so deleting the parent-property fold makes it contribute nothing and the exact-list assertion
 * in the rules gate goes red.
 *
 * Doctrine never maps it: `auto_mapping` is off and the mappings point at `src/` alone.
 *
 * @internal test fixture
 */
#[ORM\Entity]
final class InheritedColumnFixtureEntity extends AbstractFixtureColumnCarrier
{
}
