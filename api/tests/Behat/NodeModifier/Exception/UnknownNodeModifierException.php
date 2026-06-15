<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Exception;

use RuntimeException;

/**
 * Raised when a selector requests `field::<modifier>` whose name is not registered, so the
 * test author gets the available names instead of a downstream `NoSuchPropertyException` on
 * the un-stripped path.
 */
final class UnknownNodeModifierException extends RuntimeException
{
    /** @param list<string> $available */
    public function __construct(string $requested, array $available)
    {
        \sort($available);

        parent::__construct(\sprintf(
            'Unknown node modifier "::%s". Registered modifiers: %s.',
            $requested,
            [] === $available ? '(none)' : \implode(', ', $available),
        ));
    }
}
