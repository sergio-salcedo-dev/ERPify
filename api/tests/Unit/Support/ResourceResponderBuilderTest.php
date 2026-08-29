<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\ResourceResponderBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A controller test reading an emitted payload is only worth its cost while the chain it reads through
 * behaves like the deployed one on the axes that decide the wire.
 *
 * The axis with teeth is serialization metadata. A bare `new ObjectNormalizer()` reads none, so
 * `#[SerializedName]` on a Resource DTO would be invisible in every test and effective in production: the
 * test asserts `createdAt`, the endpoint emits `created_at`, and nothing is red. `ResourceDtoContractTest`
 * does not close that direction — it refuses a non-scalar property, never a renamed one — so without the
 * case below the metadata wiring would be a claim in a docblock rather than something that can fail.
 *
 * @internal
 */
#[CoversClass(ResourceResponderBuilder::class)]
final class ResourceResponderBuilderTest extends TestCase
{
    #[Test]
    public function itHonoursTheSerializationMetadataADtoCarries(): void
    {
        $response = ResourceResponderBuilder::wired()->respondCollection([
            new class {
                #[SerializedName('renamed_on_the_wire')]
                public string $named = 'value';
            },
        ]);

        $payload = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            ['data' => [['renamed_on_the_wire' => 'value']]],
            $payload,
            'The normalizer is reading no serialization metadata, so a renamed property emits under its PHP '
            . 'name here and under the declared one in production — a difference no test would see.',
        );
    }
}
