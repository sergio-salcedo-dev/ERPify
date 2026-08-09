<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures;

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
}
