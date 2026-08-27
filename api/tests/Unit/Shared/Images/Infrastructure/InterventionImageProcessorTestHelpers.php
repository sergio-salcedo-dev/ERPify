<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Shared fixtures for the {@see InterventionImageProcessor} test suite, which is split across
 * several `*Test` classes by concern (determinism, MIME handling, resource limits,
 * canonicalization, observability) — each stayed independently under the project's PHPMD
 * "too many public methods" threshold, rather than one 20-method class.
 *
 * @internal
 *
 * @phpstan-require-extends TestCase
 */
trait InterventionImageProcessorTestHelpers
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Images';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    private function processor(
        int $maxInputBytes = 20_971_520,
        int $maxDecodedPixels = 40_000_000,
        int $maxInputDimension = 10_000,
        int $maxOutputDimension = 4096,
        int $encodingQuality = 85,
    ): InterventionImageProcessor {
        return new InterventionImageProcessor(
            $maxInputBytes,
            $maxDecodedPixels,
            $maxInputDimension,
            $maxOutputDimension,
            $encodingQuality,
            $this->logger,
        );
    }

    private function fixture(string $name): string
    {
        $bytes = \file_get_contents(self::FIXTURES . '/' . $name);
        $this->assertIsString($bytes, "Fixture {$name} must be readable");

        return $bytes;
    }
}
