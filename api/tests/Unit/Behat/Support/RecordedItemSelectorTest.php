<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support;

use Erpify\Tests\Behat\Support\RecordedItemSelector;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @internal
 */
#[CoversClass(RecordedItemSelector::class)]
final class RecordedItemSelectorTest extends TestCase
{
    #[Test]
    public function itDefaultsToTheOnlyItemWhenNoneIsSelected(): void
    {
        $only = new stdClass();

        $this->assertSame($only, (new RecordedItemSelector())->select([$only], null, 'widget'));
    }

    #[Test]
    public function itReturnsTheExplicitlySelectedItem(): void
    {
        $first = new stdClass();
        $second = new stdClass();

        $this->assertSame($second, (new RecordedItemSelector())->select([$first, $second], 1, 'widget'));
    }

    #[Test]
    public function itFailsLoudlyWhenSeveralItemsExistAndNoneIsSelected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Ambiguous selection: 2 widgets were recorded');

        (new RecordedItemSelector())->select(['first', 'second'], null, 'widget');
    }

    #[Test]
    public function itFailsWhenNothingHasBeenRecorded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('No widget has been recorded');

        (new RecordedItemSelector())->select([], null, 'widget');
    }

    #[Test]
    public function itFailsWithAOneBasedNumberWhenTheSelectionIsOutOfRange(): void
    {
        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessageIsOrContains('No widget number 3');

        (new RecordedItemSelector())->select(['first'], 2, 'widget');
    }
}
