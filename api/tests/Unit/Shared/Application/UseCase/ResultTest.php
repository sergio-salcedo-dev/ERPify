<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\UseCase;

use Erpify\Shared\Application\UseCase\Result;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideOkAndCreatedFactoriesCarryPayloadCases')]
    public function testOkAndCreatedFactoriesCarryPayload(array $payload): void
    {
        $this->assertResultMatches(Result::ok($payload), $payload, Result::STATUS_OK);
        $this->assertResultMatches(Result::created($payload), $payload, Result::STATUS_CREATED);
    }

    /**
     * @return Generator<string, array{array<string, mixed>}>
     */
    public static function provideOkAndCreatedFactoriesCarryPayloadCases(): iterable
    {
        yield 'array' => [['key' => ['nested' => 'value']]];
        yield 'string' => [['key' => 'hello']];
        yield 'int' => [['key' => 42]];
        yield 'float' => [['key' => 3.14]];
        yield 'bool true' => [['key' => true]];
        yield 'bool false' => [['key' => false]];
        yield 'null' => [['key' => null]];
    }

    public function testNoContentProducesNullPayload(): void
    {
        $this->assertResultMatches(Result::noContent(), null, Result::STATUS_NO_CONTENT);
    }

    /** @param array<string, mixed>|null $expectedData */
    private function assertResultMatches(Result $result, ?array $expectedData, int $expectedStatus): void
    {
        $this->assertSame($expectedData, $result->data);
        $this->assertSame($expectedStatus, $result->status);
    }
}
