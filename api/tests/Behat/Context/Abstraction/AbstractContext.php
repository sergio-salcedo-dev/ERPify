<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Abstraction;

use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;

/**
 * @SuppressWarnings("PHPMD.NumberOfChildren")
 */
abstract class AbstractContext extends Assert implements Context
{
    /**
     * @return array{}
     */
    public static function getBundleDependencies(): array
    {
        return [];
    }
}
