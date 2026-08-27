<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\Exception\EmptyImageInput;
use Erpify\Shared\Images\Domain\Exception\FailureCategory;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AC 7 / AC 12 (resource limits enforced before a full decode) and AC 11 (empty input).
 *
 * @internal
 */
#[CoversClass(InterventionImageProcessor::class)]
final class InterventionImageProcessorResourceLimitsTest extends TestCase
{
    use InterventionImageProcessorTestHelpers;

    public function testRejectsAnEmptyInputBeforeAttemptingToDecode(): void
    {
        $this->expectException(EmptyImageInput::class);

        $this->processor()->process('');
    }

    public function testRejectsAnInputLargerThanTheConfiguredByteLimit(): void
    {
        $tooLarge = \str_repeat('a', 1001);
        $processor = $this->processor(maxInputBytes: 1000);

        try {
            $processor->process($tooLarge);
            $this->fail('Expected ImageResourceLimitExceeded to be thrown.');
        } catch (ImageResourceLimitExceeded $imageResourceLimitExceeded) {
            $this->assertSame(FailureCategory::InputTooLarge, $imageResourceLimitExceeded->failureCategory());
        }
    }

    public function testRejectsDeclaredDimensionsExceedingTheConfiguredHeaderSizeLimitBeforeDecoding(): void
    {
        // Header declares 60000x60000; production defaults reject on the dimension guard before
        // the (undecodable, garbage-body) content is ever touched by a full decode.
        $processor = $this->processor();

        try {
            $processor->process($this->fixture('oversized-header.png'));
            $this->fail('Expected ImageResourceLimitExceeded to be thrown.');
        } catch (ImageResourceLimitExceeded $imageResourceLimitExceeded) {
            $this->assertSame(FailureCategory::ResourceLimitExceeded, $imageResourceLimitExceeded->failureCategory());
        }
    }

    public function testRejectsDeclaredPixelBudgetExceededEvenWhenEachDimensionIsWithinLimit(): void
    {
        // valid.png declares 32x32 = 1024 pixels, comfortably within a generous per-side limit but
        // over a deliberately tiny pixel budget.
        $processor = $this->processor(maxDecodedPixels: 100, maxInputDimension: 100_000);

        try {
            $processor->process($this->fixture('valid.png'));
            $this->fail('Expected ImageResourceLimitExceeded to be thrown.');
        } catch (ImageResourceLimitExceeded $imageResourceLimitExceeded) {
            $this->assertSame(FailureCategory::ResourceLimitExceeded, $imageResourceLimitExceeded->failureCategory());
        }
    }
}
