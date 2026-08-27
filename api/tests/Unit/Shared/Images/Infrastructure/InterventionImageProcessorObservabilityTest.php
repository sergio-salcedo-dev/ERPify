<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\Exception\EmptyImageInput;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AC 15: privacy-safe observability.
 *
 * @internal
 */
#[CoversClass(InterventionImageProcessor::class)]
final class InterventionImageProcessorObservabilityTest extends TestCase
{
    use InterventionImageProcessorTestHelpers;

    public function testEmitsARejectedObservabilityLineWithFormatUnknownForAnEmptyInput(): void
    {
        try {
            $this->processor()->process('');
        } catch (EmptyImageInput) {
            // expected — the log is asserted below regardless of the caught exception.
        }

        $this->assertCount(1, $this->logger->records, 'Expected exactly one observability record.');

        $context = $this->logger->records[0]['context'];
        $this->assertSame('images.processing.rejected', $context['event'] ?? null);
        $this->assertSame('preflight', $context['operation'] ?? null);
        $this->assertSame('unknown', $context['format'] ?? null);
        $this->assertSame('empty_input', $context['failure_category'] ?? null);

        foreach (['bytes', 'filename', 'imageId', 'digest'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $context);
        }
    }

    public function testEmitsAFailureObservabilityLineWithTheDetectedFormatForADecodeFailure(): void
    {
        // Truncated to half its length: the SOF0 segment survives (a real, in-budget declared size
        // passes preflight), but the entropy-coded scan data does not, so the full decode fails.
        $fixture = $this->fixture('valid.jpg');
        $undecodable = \substr($fixture, 0, (int) (\strlen($fixture) / 2));

        try {
            $this->processor()->process($undecodable);
        } catch (ImageDecodingFailed) {
            // expected — the log is asserted below regardless of the caught exception.
        }

        $this->assertCount(1, $this->logger->records, 'Expected exactly one observability record.');

        $context = $this->logger->records[0]['context'];
        $this->assertSame('images.processing.failure', $context['event'] ?? null);
        $this->assertSame('decode', $context['operation'] ?? null);
        $this->assertSame('image/jpeg', $context['format'] ?? null);
        $this->assertSame('decode_failure', $context['failure_category'] ?? null);

        foreach (['bytes', 'filename', 'imageId', 'digest'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $context);
        }
    }

    public function testDoesNotEmitAnObservabilityLineWhenProcessingSucceeds(): void
    {
        $this->processor()->process($this->fixture('valid.jpg'));

        $this->assertCount(0, $this->logger->records);
    }

    public function testTheOriginalDomainExceptionStillPropagatesWhenLoggingItselfFails(): void
    {
        // Observability is never load-bearing for the rejection (Task 6) — a failing logger must
        // not swallow or replace the original domain exception.
        $processor = new InterventionImageProcessor(20_971_520, 40_000_000, 10_000, 4096, 85, new ThrowingLogger());

        $this->expectException(EmptyImageInput::class);

        $processor->process('');
    }
}
