<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\ConstraintResolution;

use Symfony\Component\Validator\Constraint;

/**
 * The GREEN half of the gate's own falsification: a constraint whose validator sits beside it under the
 * name Symfony's convention derives. Its only job is to prove the predicate can say "yes".
 */
final class ResolvableFixtureConstraint extends Constraint
{
}
