<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool\Fixtures\Frontoffice;

/**
 * Test fixture whose basename collides with its Backoffice sibling in a different bounded-context
 * namespace, exercising the resolver's ambiguity branch.
 */
final class CollidingEntity
{
}
