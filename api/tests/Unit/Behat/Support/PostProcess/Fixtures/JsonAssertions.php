<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures;

use Erpify\Tests\Behat\NodeModifier\NodeModifierLocator;
use Erpify\Tests\Behat\NodeModifier\Scalar\NullNodeModifier;
use Erpify\Tests\Behat\NodeModifier\Scalar\StringNodeModifier;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use PHPUnit\Framework\Assert;

/**
 * The trait as a Behat context holds it: extending {@see Assert} for the static assertion methods,
 * exactly the way {@see \Erpify\Tests\Behat\Context\Abstraction\AbstractContext} provides them.
 *
 * A host of its own rather than using the trait from the TestCase, so the subject and the test judging
 * it never share an assertion counter — and so a name the trait declares is measured against a bare
 * host instead of against whatever a TestCase happens to define.
 */
final class JsonAssertions extends Assert
{
    use JsonToolTrait;

    /**
     * Two node modifiers, not the container's set: neither claims a value by auto-detection, so an
     * expected value reaches the comparison exactly as it was written and the subject under test stays
     * the assertion rather than modifier resolution. `null` is registered because the cases about the
     * `::<modifier>` suffix name it explicitly.
     */
    public static function withScalarModifiers(): self
    {
        $assertions = new self();
        $assertions->setNodeModifierLocator(
            new NodeModifierLocator([new NullNodeModifier(), new StringNodeModifier()]),
        );

        return $assertions;
    }
}
