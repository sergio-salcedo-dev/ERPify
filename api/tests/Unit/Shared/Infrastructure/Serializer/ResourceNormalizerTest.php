<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Serializer;

use ArrayObject;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(ResourceNormalizer::class)]
final class ResourceNormalizerTest extends TestCase
{
    public function testDelegatesToNormalizerWithFormatAndGroupsAndReturnsArrayUnchanged(): void
    {
        $resource = new stdClass();
        $expected = ['id' => '7d4d', 'name' => 'Acme'];

        $innerNormalizer = $this->createMock(NormalizerInterface::class);
        $innerNormalizer
            ->expects($this->once())
            ->method('normalize')
            ->with($resource, 'json', ['groups' => ['identifiable', 'bank:detail']])
            ->willReturn($expected)
        ;

        $resourceNormalizer = new ResourceNormalizer($innerNormalizer);

        $this->assertSame(
            $expected,
            $resourceNormalizer->toArray($resource, ['identifiable', 'bank:detail']),
        );
    }

    public function testPassesCustomFormatThroughAndReturnsInnerArray(): void
    {
        $resource = new stdClass();
        $expected = ['foo' => 'bar'];

        $innerNormalizer = $this->createMock(NormalizerInterface::class);
        $innerNormalizer
            ->expects($this->once())
            ->method('normalize')
            ->with($resource, 'xml', ['groups' => []])
            ->willReturn($expected)
        ;

        $this->assertSame(
            $expected,
            (new ResourceNormalizer($innerNormalizer))->toArray($resource, [], 'xml'),
        );
    }

    public function testUnwrapsArrayObjectReturnedByInnerNormalizer(): void
    {
        $resource = new stdClass();
        $arrayObject = new ArrayObject(['key' => 'value']);

        $innerNormalizer = $this->createStub(NormalizerInterface::class);
        $innerNormalizer
            ->method('normalize')
            ->willReturn($arrayObject)
        ;

        $this->assertSame(
            ['key' => 'value'],
            (new ResourceNormalizer($innerNormalizer))->toArray($resource, []),
        );
    }

    public function testThrowsWhenInnerNormalizerReturnsNonArray(): void
    {
        $innerNormalizer = $this->createStub(NormalizerInterface::class);
        $innerNormalizer
            ->method('normalize')
            ->willReturn('not-an-array')
        ;

        $resourceNormalizer = new ResourceNormalizer($innerNormalizer);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected normalize() to return array, got string.');

        $resourceNormalizer->toArray(new stdClass(), ['some-group']);
    }
}
