<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction;

use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;
use Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction\Fixtures\FullyLabeledIntEnum;
use Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction\Fixtures\MissingLabelIntEnum;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class HumanReadableIntEnumTraitTest extends TestCase
{
    public function testGetLabelReturnsTheCaseLabel(): void
    {
        $this->assertSame('first', FullyLabeledIntEnum::FIRST->getLabel());
    }

    public function testGetLabelsReturnsEveryLabelAsAList(): void
    {
        $this->assertSame(['first', 'second'], FullyLabeledIntEnum::getLabels());
    }

    public function testCaseMissingTheAttributeThrowsWhenLabelsAreAccessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(HumanReadableIntEnumValue::class);

        MissingLabelIntEnum::getLabels();
    }
}
