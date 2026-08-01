<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Domain;

use Attribute;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Tests\Unit\Shared\Privacy\Domain\Fixtures\SampleWithPersonReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The declaration side of the person-reference axis. Everything that reads this marker reads it by
 * reflection, so what has to hold is that the owner survives the round trip to the property and back, and
 * that the two shape decisions the readers depend on are what they think they are.
 *
 * @internal
 */
#[CoversClass(PersonSubjectReference::class)]
final class PersonSubjectReferenceTest extends TestCase
{
    #[Test]
    public function itCarriesTheErasureOwnerBackFromTheAnnotatedProperty(): void
    {
        $attributes = (new ReflectionProperty(SampleWithPersonReference::class, 'userId'))
            ->getAttributes(PersonSubjectReference::class)
        ;

        $this->assertCount(1, $attributes);
        $this->assertSame(
            'src/Iam/Session/Application/PurgeUserSessions.php',
            $attributes[0]->newInstance()->erasedBy,
        );
    }

    #[Test]
    public function anUnmarkedPropertyCarriesNothing(): void
    {
        // Without this the read above would be indistinguishable from one that answers for every property.
        $this->assertSame([], (new ReflectionProperty(SampleWithPersonReference::class, 'organizationId'))
            ->getAttributes(PersonSubjectReference::class));
    }

    #[Test]
    public function itTargetsPropertiesOnlyAndIsNotRepeatable(): void
    {
        $declaration = (new ReflectionClass(PersonSubjectReference::class))->getAttributes(Attribute::class);
        $this->assertCount(1, $declaration);

        // The exact flag value carries both facts the readers rest on, which is why it is asserted whole
        // rather than probed bit by bit. Anything WIDER than a property would admit a declaration the
        // registry can never carry, because the universe it is checked against is derived from persisted
        // columns; and the absence of IS_REPEATABLE is what makes "one owner per property" true — were a
        // second declaration allowed, it would silently win and its disagreement with the registry would
        // never surface.
        $this->assertSame(Attribute::TARGET_PROPERTY, $declaration[0]->newInstance()->flags);
    }
}
