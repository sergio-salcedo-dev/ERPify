<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Erpify\Shared\Images\Infrastructure\MediaTypeEncoderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * AC 1 / AC 3 (deterministic pipeline, digest over canonical bytes) and the canonicalization
 * contract's #3 (output family = detected input family). AC 10 (no filename in the signature)
 * lives here too — it is a structural property of the same public entry point.
 *
 * @internal
 */
#[CoversClass(InterventionImageProcessor::class)]
#[CoversClass(CanonicalImage::class)]
#[CoversClass(MediaTypeEncoderFactory::class)]
final class InterventionImageProcessorDeterminismTest extends TestCase
{
    use InterventionImageProcessorTestHelpers;

    public function testCanonicalizingTheSameBytesTwiceProducesIdenticalCanonicalBytesAndDigest(): void
    {
        $bytes = $this->fixture('valid.jpg');
        $processor = $this->processor();

        $first = $processor->process($bytes);
        $second = $processor->process($bytes);

        $this->assertSame($first->bytes, $second->bytes);
        $this->assertSame($first->digest, $second->digest);
    }

    public function testDigestIsSha256OfTheCanonicalBytesNeverTheOriginalUpload(): void
    {
        $bytes = $this->fixture('valid.png');
        $canonical = $this->processor()->process($bytes);

        $this->assertSame(\hash('sha256', $canonical->bytes), $canonical->digest);
        $this->assertNotSame(\hash('sha256', $bytes), $canonical->digest);
    }

    public function testCanonicalMediaTypeIsTheSameFamilyAsTheDetectedInputForEveryAllowlistedFormat(): void
    {
        $processor = $this->processor();

        $this->assertSame('image/jpeg', $processor->process($this->fixture('valid.jpg'))->mediaType);
        $this->assertSame('image/png', $processor->process($this->fixture('valid.png'))->mediaType);
        $this->assertSame('image/webp', $processor->process($this->fixture('valid.webp'))->mediaType);
        $this->assertSame('image/gif', $processor->process($this->fixture('valid.gif'))->mediaType);
    }

    public function testProcessAcceptsOnlyBytesAndAnOptionalDeclaredMediaTypeNeverAFilename(): void
    {
        $reflection = new ReflectionMethod(InterventionImageProcessor::class, 'process');

        $parameterNames = \array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters(),
        );

        $this->assertSame(['bytes', 'declaredMediaType'], $parameterNames);
    }
}
