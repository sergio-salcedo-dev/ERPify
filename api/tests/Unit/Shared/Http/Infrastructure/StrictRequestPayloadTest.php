<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * The attribute carries the whole policy, so its constructor is the policy: everything below is a way for the
 * guarantee to be lost without any call site changing. Inheriting {@see MapRequestPayload} is what makes Symfony
 * resolve the subclass at all — it fetches payload attributes with `ArgumentMetadata::IS_INSTANCEOF` — and the
 * union order is what stops a caller re-enabling the very thing the type exists to forbid.
 *
 * @internal
 */
#[CoversClass(StrictRequestPayload::class)]
final class StrictRequestPayloadTest extends TestCase
{
    #[Test]
    public function itIsResolvedExactlyLikeThePlainPayloadAttribute(): void
    {
        // Losing this parent silently reverts every annotated endpoint to accepting undeclared members.
        $parent = (new ReflectionClass(StrictRequestPayload::class))->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(MapRequestPayload::class, $parent->getName());
    }

    #[Test]
    public function itRefusesUndeclaredMembersWithoutBeingAskedTo(): void
    {
        $payload = new StrictRequestPayload();

        $this->assertSame([AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false], $payload->serializationContext);
    }

    #[Test]
    public function aCallSiteCannotLoosenStrictness(): void
    {
        // The union puts the policy on the left, so a caller-supplied `true` never wins.
        $payload = new StrictRequestPayload(
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => true],
        );

        $this->assertSame([AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false], $payload->serializationContext);
    }

    #[Test]
    public function itKeepsTheRestOfTheCallSiteSerializerContext(): void
    {
        $payload = new StrictRequestPayload(
            serializationContext: [AbstractNormalizer::GROUPS => ['write']],
        );

        $this->assertSame(
            [
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                AbstractNormalizer::GROUPS => ['write'],
            ],
            $payload->serializationContext,
        );
    }

    #[Test]
    public function itForwardsTheOptionsItDoesNotOwn(): void
    {
        $payload = new StrictRequestPayload(
            acceptFormat: 'json',
            validationGroups: ['create'],
            type: self::class,
        );

        $this->assertSame('json', $payload->acceptFormat);
        $this->assertSame(['create'], $payload->validationGroups);
        $this->assertSame(self::class, $payload->type);
    }
}
