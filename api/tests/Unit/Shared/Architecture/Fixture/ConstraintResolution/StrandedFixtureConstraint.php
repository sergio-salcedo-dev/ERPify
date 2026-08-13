<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\ConstraintResolution;

use Symfony\Component\Validator\Constraint;

/**
 * The RED half of the gate's own falsification: a constraint with no validator at the name Symfony's
 * convention derives, which is what a constraint separated from its validator looks like. Deliberately
 * has no `…Validator` sibling — do not "fix" it by adding one.
 */
final class StrandedFixtureConstraint extends Constraint
{
}
