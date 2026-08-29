<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Http\Infrastructure\Responder\JsonResponder;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Serialization\Infrastructure\ResourceNormalizer;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The normalize-then-respond chain, assembled so a controller test can read what an endpoint emits instead
 * of what it hands a double.
 *
 * Nothing here is a stand-in: {@see ResourceResponder} and {@see ResourceNormalizer} are `final readonly`
 * and {@see JsonResponder} is `final`, so a test that wants the emitted payload has to build the chain —
 * five types, which on its own puts a case over the PHPMD object-coupling budget. Keeping the assembly in
 * one place is what lets each case be about its own claim; it constructs `final` collaborators rather than
 * abstracting over them.
 *
 * **The inner normalizer carries its metadata, and that is not decoration.** A bare `new ObjectNormalizer()`
 * reads no serialization metadata at all, so `#[SerializedName]`, `#[SerializedPath]` and `#[Ignore]` on a
 * Resource DTO would be invisible here and effective in production — a test asserting `createdAt` would
 * stay green while the endpoint emitted `created_at`. `ResourceDtoContractTest` does not close that
 * direction: it refuses a non-scalar property, never a renamed one.
 *
 * It is still not the container's own service. What the container injects is the full `serializer`, which
 * carries every registered normalizer and encoder; this carries one normalizer and no encoder, which is all
 * a flat, scalar-only Resource DTO needs by contract. A DTO that outgrew that contract would be caught by
 * `ResourceDtoContractTest` before it reached here.
 */
final class ResourceResponderBuilder
{
    public static function wired(): ResourceResponder
    {
        $metadata = new ClassMetadataFactory(new AttributeLoader());

        return new ResourceResponder(
            new ResourceNormalizer(
                new Serializer([new ObjectNormalizer($metadata, new MetadataAwareNameConverter($metadata))]),
            ),
            new JsonResponder(),
        );
    }
}
