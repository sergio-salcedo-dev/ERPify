<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsFactory;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\Exception\EmptyImageInput;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageLengthRequired;
use Erpify\Shared\Images\Domain\Exception\ImageProcessingFailed;
use Erpify\Shared\Images\Domain\Exception\ImageResourceLimitExceeded;
use Erpify\Shared\Images\Domain\Exception\UnsupportedImageFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The wire contract of an image write's failures (`docs/api-error-contract.md`, "Image writes"): every refusal a
 * caller can cause is a 4xx with a stable `type`, and a fault of ours stays a 5xx.
 *
 * @internal
 */
#[CoversClass(ProblemDetailsFactory::class)]
final class ImageExceptionProblemMappingTest extends TestCase
{
    private const string CID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    #[DataProvider('provideACallersRefusalIsA4xxWithItsOwnTypeCases')]
    public function testACallersRefusalIsA4xxWithItsOwnType(Throwable $refusal, int $status, string $type): void
    {
        $problem = $this->factory()->fromThrowable($refusal, self::CID, self::INSTANCE);

        $this->assertSame($status, $problem->status);
        $this->assertSame($type, $problem->type);
    }

    /**
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function provideACallersRefusalIsA4xxWithItsOwnTypeCases(): iterable
    {
        $invalid = 'image-invalid';
        $tooLarge = 'image-too-large';
        $unsupported = 'image-unsupported-type';
        $length = 'image-length-required';

        yield 'empty body' => [new EmptyImageInput(), 422, $invalid];
        yield 'bytes that do not decode' => [new ImageDecodingFailed(new RuntimeException('x')), 422, $invalid];
        yield 'over the byte cap' => [ImageResourceLimitExceeded::inputTooLarge(), 413, $tooLarge];
        yield 'over the dimension limit' => [ImageResourceLimitExceeded::inputDimensionExceeded(), 413, $tooLarge];
        yield 'over the pixel budget' => [ImageResourceLimitExceeded::decodedPixelsExceeded(), 413, $tooLarge];
        yield 'a format outside the allowlist' => [UnsupportedImageFormat::notInAllowlist(), 415, $unsupported];
        yield 'a declaration that contradicts the bytes' => [UnsupportedImageFormat::mimeMismatch(), 415, $unsupported];
        yield 'no declared length' => [ImageLengthRequired::missing(), 411, $length];
        yield 'a length that is not the bytes read' => [ImageLengthRequired::notEqualToBytesRead(), 411, $length];
        yield 'a derivative whose origin refused it' => [
            DerivativeOriginNotLive::create(),
            409,
            'image-origin-not-live',
        ];
    }

    public function testAProcessingFaultOfOursStaysA5xx(): void
    {
        $problem = $this->factory()->fromThrowable(
            new ImageProcessingFailed(new RuntimeException('encoder')),
            self::CID,
            self::INSTANCE,
        );

        $this->assertSame(500, $problem->status);
    }

    public function testNoRefusalQuotesTheBytesOrTheDeclaredType(): void
    {
        foreach (self::provideACallersRefusalIsA4xxWithItsOwnTypeCases() as [$refusal]) {
            $problem = $this->factory()->fromThrowable($refusal, self::CID, self::INSTANCE);

            $this->assertSame([], \array_diff(\array_keys($problem->extensions), ['debug']));
        }
    }

    private function factory(): ProblemDetailsFactory
    {
        return new ProblemDetailsFactory('prod', new NullLogger());
    }
}
