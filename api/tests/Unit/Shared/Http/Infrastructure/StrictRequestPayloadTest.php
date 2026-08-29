<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The attribute carries the whole policy, so its constructor is the policy: everything below is a way for the
 * guarantee to be lost without any call site changing. Inheriting {@see MapRequestPayload} is what makes Symfony
 * resolve the subclass at all — it fetches payload attributes with `ArgumentMetadata::IS_INSTANCEOF` — and the
 * union order is what stops a caller re-enabling the very thing the type exists to forbid.
 *
 * The coupling suppression is measured at 18, and it is the subject's stack rather than this class's:
 * asserting what a CALLER sees means driving Symfony's real `RequestPayloadValueResolver`, which cannot be
 * stood up without a Serializer, an ObjectNormalizer, an AttributeLoader, a ClassMetadataFactory and a
 * JsonEncoder — thirteen of the twenty imports are used exactly once, all of them in that assembly. The
 * alternative measured and rejected was asserting the attribute's stored `acceptFormat` value instead,
 * which passes over the only thing that matters: whether a form-encoded body is actually refused.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
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

    #[Test]
    public function itRefusesAFormEncodedBodyWithoutBeingAskedTo(): void
    {
        // The endpoints carrying this attribute speak JSON, so a form-encoded body is a caller mistake or a
        // probe — never a supported spelling of the same request.
        $request = Request::create('/probe', Request::METHOD_POST, ['name' => 'Form Probe']);

        $this->expectException(UnsupportedMediaTypeHttpException::class);

        $this->mapPayload($request);
    }

    #[Test]
    public function itAcceptsAJsonBodyWithoutBeingAskedTo(): void
    {
        // Control: the refusal is the format policy speaking, not the harness throwing whatever it is handed.
        $request = Request::create(
            '/probe',
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $this->assertInstanceOf(stdClass::class, $this->mapPayload($request));
    }

    /**
     * The resolver gates its format check on truthiness — `if ($attribute->acceptFormat && ...)` — so a falsy
     * argument skips the check outright instead of loosening it, and the endpoint accepts the form-encoded and
     * multipart bodies this attribute exists to refuse.
     *
     * The cases below are EXAMPLES of that predicate, never the definition of it: `'0'` is here because a
     * guard written as a list of `null`, `[]` and `''` reads as exhaustive and admits it. The guard is one
     * truthiness test for exactly that reason, so this provider can be short without the policy being short.
     *
     * The type half is read through reflection rather than compared against a literal: a signature asserted
     * against a copy of itself is a tautology, and the parameter is what a call site actually meets.
     */
    #[Test]
    public function itsAcceptFormatCannotBeSpelledAsNull(): void
    {
        $constructor = (new ReflectionClass(StrictRequestPayload::class))->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $acceptFormat = \array_find(
            $constructor->getParameters(),
            static fn (ReflectionParameter $parameter): bool => 'acceptFormat' === $parameter->getName(),
        );

        $this->assertInstanceOf(
            ReflectionParameter::class,
            $acceptFormat,
            'The constructor no longer declares an $acceptFormat parameter.',
        );

        $type = $acceptFormat->getType();

        $this->assertInstanceOf(ReflectionType::class, $type);
        $this->assertFalse(
            $type->allowsNull(),
            'acceptFormat admits null again. The parent defaults to it and the resolver treats it as falsy, '
            . 'so the format check is skipped and a form-encoded body maps like a JSON one.',
        );
    }

    /**
     * @param array<string>|string $acceptFormat
     */
    #[DataProvider('provideItRefusesAFalsyAcceptFormatCases')]
    #[Test]
    public function itRefusesAFalsyAcceptFormat(array|string $acceptFormat): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StrictRequestPayload(acceptFormat: $acceptFormat);
    }

    /**
     * @return iterable<string, array{0: array<string>|string}>
     */
    public static function provideItRefusesAFalsyAcceptFormatCases(): iterable
    {
        yield 'the empty list' => [[]];
        yield 'the empty string' => [''];
        yield "the string '0'" => ['0'];
    }

    /**
     * Runs a payload with no explicit `acceptFormat` through Symfony's own resolver, so the assertion is the
     * status a caller receives rather than the value the constructor stored. The mapped type is immaterial:
     * the format check runs before any denormalization.
     */
    private function mapPayload(Request $request): mixed
    {
        // The metadata factory is not decoration: the strict serializer context refuses to run without one.
        $normalizer = new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()));
        $resolver = new RequestPayloadValueResolver(new Serializer([$normalizer], [new JsonEncoder()]));
        $metadata = new ArgumentMetadata('payload', stdClass::class, false, false, null, false, [
            new StrictRequestPayload(),
        ]);

        $event = new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): Response => new Response(),
            [...$resolver->resolve($request, $metadata)],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $resolver->onKernelControllerArguments($event);

        return $event->getArguments()[0] ?? null;
    }
}
