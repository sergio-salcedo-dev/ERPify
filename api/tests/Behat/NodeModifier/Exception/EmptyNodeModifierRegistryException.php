<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Exception;

use RuntimeException;

/**
 * Raised when the `test.node_modifier` iterable is empty — i.e. no modifier reached the
 * locator. This guards the wiring (the `_instanceof` tag in `config/services_behat.yaml`):
 * a regression that stops tagging the modifiers surfaces here as an explicit failure when
 * the locator is constructed, instead of every `::<modifier>` assertion silently degrading
 * to a raw `NoSuchPropertyException`.
 */
final class EmptyNodeModifierRegistryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No node modifiers are registered: the "test.node_modifier" tag reached nothing. '
            . 'Check the _instanceof tag for NodeModifierInterface in config/services_behat.yaml.',
        );
    }
}
