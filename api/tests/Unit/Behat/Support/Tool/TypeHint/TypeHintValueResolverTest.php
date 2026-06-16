<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool\TypeHint;

use DateTime;
use Erpify\Tests\Behat\Support\Tool\TypeHint\TypeHintValueResolver;
use Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures\StringValueObject;
use Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures\ThrowingValueObject;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TypeHintValueResolver::class)]
final class TypeHintValueResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItResolvesValueTypePairToTheExpectedResultCases')]
    public function itResolvesValueTypePairToTheExpectedResult(mixed $value, ?string $type, mixed $expected): void
    {
        $resolver = new TypeHintValueResolver();

        $resolved = $resolver->resolve($value, $type);

        $this->assertSame($expected, $resolved);
    }

    /**
     * @return Generator<string, array{mixed, ?string, mixed}>
     */
    public static function provideItResolvesValueTypePairToTheExpectedResultCases(): iterable
    {
        yield 'null literal short-circuits any type' => ['null', StringValueObject::class, null];

        yield 'no type passes the raw value through' => ['raw', null, 'raw'];

        yield 'builtin type passes the raw value through' => ['raw', 'string', 'raw'];

        // null is "absent", not malformed: it passes through unchanged rather than being rejected
        // like a non-scalar array/object — consistent with how the date node modifier treats null.
        yield 'absent value passes through the date hint' => [null, 'date', null];
    }

    #[Test]
    public function itResolvesTheDateHintToAMutableDateTime(): void
    {
        $resolver = new TypeHintValueResolver();

        $resolved = $resolver->resolve('2026-06-06 10:00:00', 'date');

        $this->assertInstanceOf(DateTime::class, $resolved);
        $this->assertSame('2026-06-06 10:00:00', $resolved->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itConstructsAValueObjectFromAClassType(): void
    {
        $resolver = new TypeHintValueResolver();

        $resolved = $resolver->resolve('iban-123', StringValueObject::class);

        $this->assertInstanceOf(StringValueObject::class, $resolved);
        $this->assertSame('iban-123', $resolved->value);
    }

    #[Test]
    public function itFallsBackToTheRawValueWhenTheConstructorThrows(): void
    {
        $resolver = new TypeHintValueResolver();

        $resolved = $resolver->resolve('rejected', ThrowingValueObject::class);

        $this->assertSame('rejected', $resolved);
    }

    #[Test]
    public function itRejectsANonScalarValueForTheDateHint(): void
    {
        $resolver = new TypeHintValueResolver();

        $this->expectException(InvalidArgumentException::class);

        $resolver->resolve(['not', 'scalar'], 'date');
    }
}
