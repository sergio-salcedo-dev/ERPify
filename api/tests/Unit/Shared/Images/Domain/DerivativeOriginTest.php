<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DerivativeOrigin::class)]
final class DerivativeOriginTest extends TestCase
{
    #[Test]
    public function itKeepsTheTagAndLowerCasesTheId(): void
    {
        $origin = new DerivativeOrigin('Documents.Document', '0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1E01');

        $this->assertSame('Documents.Document', $origin->type);
        $this->assertSame('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01', $origin->id);
    }

    #[Test]
    public function aTagOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $type = 'A.B' . \str_repeat('b', DerivativeOrigin::TYPE_MAX_LENGTH - 3);

        $this->assertSame($type, (new DerivativeOrigin($type, '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01'))->type);
    }

    #[Test]
    #[DataProvider('provideATagThatIsNotAContextAndAnAggregateIsRefusedCases')]
    public function aTagThatIsNotAContextAndAnAggregateIsRefused(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DerivativeOrigin($type, '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideATagThatIsNotAContextAndAnAggregateIsRefusedCases(): iterable
    {
        yield 'empty' => [''];
        yield 'no dot' => ['Documents'];
        yield 'lower-case context' => ['documents.Document'];
        yield 'three segments' => ['Documents.Document.Page'];
        yield 'a trailing newline' => ["Documents.Document\n"];
        yield 'a space' => ['Documents. Document'];
        yield 'lower-case aggregate' => ['Documents.document'];
        yield 'one character too long' => ['A.B' . \str_repeat('b', DerivativeOrigin::TYPE_MAX_LENGTH - 2)];
    }

    #[Test]
    public function anIdThatIsNotAUuidIsRefused(): void
    {
        $this->expectException(InvalidUuidException::class);

        new DerivativeOrigin('Documents.Document', 'not-a-uuid');
    }
}
