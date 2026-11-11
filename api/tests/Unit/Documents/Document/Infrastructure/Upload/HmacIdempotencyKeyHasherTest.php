<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Infrastructure\Upload\HmacIdempotencyKeyHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HmacIdempotencyKeyHasher::class)]
final class HmacIdempotencyKeyHasherTest extends TestCase
{
    private const string ACTOR = '0190e9c2-7b5a-7d40-9c8f-aabbccddeeff';

    public function testTheSameActorAndKeyAlwaysHashToTheSameDigest(): void
    {
        $hasher = new HmacIdempotencyKeyHasher('secret');

        $this->assertSame($hasher->hash(self::ACTOR, 'key-1'), $hasher->hash(self::ACTOR, 'key-1'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash(self::ACTOR, 'key-1'));
    }

    public function testTheActorIsCaseFolded(): void
    {
        $hasher = new HmacIdempotencyKeyHasher('secret');

        $this->assertSame(
            $hasher->hash(self::ACTOR, 'key-1'),
            $hasher->hash(\strtoupper(self::ACTOR), 'key-1'),
        );
    }

    public function testTwoActorsWithTheSameKeyNeverMeet(): void
    {
        $hasher = new HmacIdempotencyKeyHasher('secret');

        $this->assertNotSame(
            $hasher->hash(self::ACTOR, 'key-1'),
            $hasher->hash('0190e9c2-7b5a-7d40-9c8f-000000000000', 'key-1'),
        );
    }

    public function testAnActorKeyBoundaryCannotBeShifted(): void
    {
        $hasher = new HmacIdempotencyKeyHasher('secret');

        $this->assertNotSame($hasher->hash('ab', 'c'), $hasher->hash('a', 'bc'));
    }

    public function testTheDigestIsKeyedByTheSecret(): void
    {
        $this->assertNotSame(
            (new HmacIdempotencyKeyHasher('one'))->hash(self::ACTOR, 'key-1'),
            (new HmacIdempotencyKeyHasher('two'))->hash(self::ACTOR, 'key-1'),
        );
    }
}
