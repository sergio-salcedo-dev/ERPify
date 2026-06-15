<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Exception;

use Erpify\Tests\Behat\NodeModifier\NodeModifierInterface;
use RuntimeException;

/**
 * Raised when two registered modifiers claim the same name: name-keyed resolution would be
 * ambiguous, so the collision is rejected at locator build time rather than silently letting
 * one shadow the other.
 */
final class DuplicateNodeModifierException extends RuntimeException
{
    public function __construct(string $name, NodeModifierInterface $first, NodeModifierInterface $second)
    {
        parent::__construct(\sprintf(
            'Duplicate node modifier "::%s" declared by %s and %s.',
            $name,
            $first::class,
            $second::class,
        ));
    }
}
