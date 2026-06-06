<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Tool\TypeHint;

use DateTime;
use Erpify\Tests\Behat\Support\Tool\TypeHint\TypeHintValueResolver;
use Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures\StringValueObject;
use Erpify\Tests\Unit\Behat\Support\Tool\TypeHint\Fixtures\ThrowingValueObject;
use Erpify\Tests\Unit\Shared\Domain\Enum\Abstraction\Fixtures\FullyLabeledIntEnum;
use Generator;
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
        yield 'null literal short-circuits any type' => ['null', FullyLabeledIntEnum::class, null];

        yield 'enum label resolves to its case' => ['first', FullyLabeledIntEnum::class, FullyLabeledIntEnum::FIRST];

        yield 'enum label without a match falls back to the raw label' => [
            'unknown',
            FullyLabeledIntEnum::class,
            'unknown',
        ];

        yield 'enum label array resolves item by item preserving keys' => [
            ['a' => 'first', 'b' => 'second', 'c' => 'unknown'],
            FullyLabeledIntEnum::class,
            ['a' => FullyLabeledIntEnum::FIRST, 'b' => FullyLabeledIntEnum::SECOND, 'c' => 'unknown'],
        ];

        yield 'no type passes the raw value through' => ['raw', null, 'raw'];

        yield 'builtin type passes the raw value through' => ['raw', 'string', 'raw'];
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
}
