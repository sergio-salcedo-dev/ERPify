<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Keyset;

use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\Exception\InvalidCursorCause;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\CursorCodec;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetPaginator;
use Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Keyset\Fixtures\InMemoryKeysetSource;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The keyset walk with no store behind it: an {@see InMemoryKeysetSource} answers the fetches, so what is
 * pinned here is the paginator's own algorithm — the +1 row, the `before` inversion, the page flags, the
 * outbound cursors, the empty-page recovery cursor and the column-count guard — once, for every adapter.
 * The ORM and DBAL adapters are pinned against real Postgres by their own functional tests.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(KeysetPaginator::class)]
final class KeysetPaginatorTest extends TestCase
{
    private const string SECRET = 'keyset-paginator-unit-test-secret';

    private CursorCodec $codec;

    private KeysetPaginator $paginator;

    #[Override]
    protected function setUp(): void
    {
        $this->codec = new CursorCodec(self::SECRET);
        $this->paginator = new KeysetPaginator($this->codec);
    }

    #[Test]
    public function aFirstPageFetchesOneRowPastTheLimitAndReportsMoreAhead(): void
    {
        $source = $this->source(limit: 2);

        $page = $this->paginator->paginate($source, null, NavigationDirection::After);

        $this->assertSame(['a', 'b'], $page->items);
        $this->assertTrue($page->hasNext);
        $this->assertFalse($page->hasPrev);
        $this->assertNull($page->count);
        $this->assertSame([$this->fetchOf(null, NavigationDirection::After)], $source->fetches);
    }

    #[Test]
    public function anExactlyFullLastPageReportsNothingAhead(): void
    {
        $page = $this->paginator->paginate($this->source(limit: 5), null, NavigationDirection::After);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $page->items);
        $this->assertFalse($page->hasNext);
        $this->assertFalse($page->hasPrev);
    }

    #[Test]
    public function itSignsBothBoundaryRowsInTheCodecWireFormat(): void
    {
        $page = $this->paginator->paginate($this->source(limit: 2), null, NavigationDirection::After);

        // Byte-identical to a cursor minted straight through the codec: the wire format is the codec's alone.
        $this->assertSame($this->token(Cursor::DIRECTION_AFTER, ['rank' => '2', 'id' => 'b']), $page->nextCursor);
        $this->assertSame($this->token(Cursor::DIRECTION_BEFORE, ['rank' => '1', 'id' => 'a']), $page->prevCursor);
    }

    #[Test]
    public function anAfterCursorWalksForwardFromItsBoundary(): void
    {
        $first = $this->paginator->paginate($this->source(limit: 2), null, NavigationDirection::After);
        $source = $this->source(limit: 2);

        $second = $this->paginator->paginate($source, $first->nextCursor, NavigationDirection::After);

        $this->assertSame(['c', 'd'], $second->items);
        $this->assertTrue($second->hasNext);
        $this->assertTrue($second->hasPrev);
        $this->assertSame([$this->fetchOf(['rank' => '2', 'id' => 'b'], NavigationDirection::After)], $source->fetches);
    }

    #[Test]
    public function aBeforeCursorScansInvertedAndRestoresTheCallerFacingOrder(): void
    {
        $first = $this->paginator->paginate($this->source(limit: 2), null, NavigationDirection::After);
        $second = $this->paginator->paginate($this->source(limit: 2), $first->nextCursor, NavigationDirection::After);
        $source = $this->source(limit: 2);

        $back = $this->paginator->paginate($source, $second->prevCursor, NavigationDirection::Before);

        $this->assertSame(['a', 'b'], $back->items);
        $this->assertTrue($back->hasNext, 'a before walk came from a page ahead, so there is always a next');
        $this->assertFalse($back->hasPrev, 'no extra row was found before the first row');
        $this->assertSame(
            [$this->fetchOf(['rank' => '3', 'id' => 'c'], NavigationDirection::Before)],
            $source->fetches,
        );
        $this->assertSame($first->nextCursor, $back->nextCursor);
        $this->assertSame($first->prevCursor, $back->prevCursor);
    }

    #[Test]
    public function aBeforeWalkReportsMoreBehindWhenItsExtraRowExists(): void
    {
        $last = $this->token(Cursor::DIRECTION_BEFORE, ['rank' => '5', 'id' => 'e']);

        $page = $this->paginator->paginate($this->source(limit: 2), $last, NavigationDirection::Before);

        $this->assertSame(['c', 'd'], $page->items);
        $this->assertTrue($page->hasNext);
        $this->assertTrue($page->hasPrev);
    }

    #[Test]
    public function anEmptyAfterPageKeepsAPreviousAffordanceWithARecoveryCursor(): void
    {
        $values = ['rank' => '5', 'id' => 'e'];

        $page = $this->paginator->paginate(
            $this->source(limit: 2, count: 5),
            $this->token(Cursor::DIRECTION_AFTER, $values),
            NavigationDirection::After,
        );

        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasNext);
        $this->assertTrue($page->hasPrev);
        $this->assertSame(5, $page->count);
        $this->assertNull($page->nextCursor);
        $this->assertSame($this->token(Cursor::DIRECTION_BEFORE, $values), $page->prevCursor);
    }

    #[Test]
    public function anEmptyBeforePageKeepsANextAffordanceWithARecoveryCursor(): void
    {
        $values = ['rank' => '1', 'id' => 'a'];

        $page = $this->paginator->paginate(
            $this->source(limit: 2),
            $this->token(Cursor::DIRECTION_BEFORE, $values),
            NavigationDirection::Before,
        );

        $this->assertSame([], $page->items);
        $this->assertTrue($page->hasNext);
        $this->assertFalse($page->hasPrev);
        $this->assertSame($this->token(Cursor::DIRECTION_AFTER, $values), $page->nextCursor);
        $this->assertNull($page->prevCursor);
    }

    #[Test]
    public function anEmptyFirstPageHasNoAffordanceAndNoCursor(): void
    {
        $page = $this->paginator->paginate(
            new InMemoryKeysetSource([], limit: 2, count: 0),
            '',
            NavigationDirection::After,
        );

        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasNext);
        $this->assertFalse($page->hasPrev);
        $this->assertSame(0, $page->count);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->prevCursor);
    }

    #[Test]
    public function aCursorMissingAnOrderingKeyIsAMalformedCursorAndNothingIsFetched(): void
    {
        $source = $this->source(limit: 2);

        $this->assertRefused(
            InvalidCursorCause::Payload,
            fn (): Page => $this->paginator->paginate(
                $source,
                $this->token(Cursor::DIRECTION_AFTER, ['id' => 'b']),
                NavigationDirection::After,
            ),
        );
        $this->assertSame([], $source->fetches);
    }

    #[Test]
    public function theRoutingDirectionNotTheCursorDecidesWhichDirectionItIsCheckedAgainst(): void
    {
        $afterToken = $this->token(Cursor::DIRECTION_AFTER, ['rank' => '2', 'id' => 'b']);

        $this->assertRefused(
            InvalidCursorCause::Payload,
            fn (): Page => $this->paginator->paginate(
                $this->source(limit: 2),
                $afterToken,
                NavigationDirection::Before,
            ),
        );
    }

    #[Test]
    public function aCursorSignedForAnotherQueryIsRefusedOnItsFingerprint(): void
    {
        $foreign = $this->codec->encode(
            new Cursor(CursorCodec::CURRENT_VERSION, Cursor::DIRECTION_AFTER, ['rank' => '2', 'id' => 'b'], 'other'),
        );

        $this->assertRefused(
            InvalidCursorCause::Fingerprint,
            fn (): Page => $this->paginator->paginate($this->source(limit: 2), $foreign, NavigationDirection::After),
        );
    }

    private function source(int $limit, ?int $count = null): InMemoryKeysetSource
    {
        return new InMemoryKeysetSource(
            [
                ['rank' => '3', 'id' => 'c'],
                ['rank' => '1', 'id' => 'a'],
                ['rank' => '5', 'id' => 'e'],
                ['rank' => '2', 'id' => 'b'],
                ['rank' => '4', 'id' => 'd'],
            ],
            $limit,
            $count,
        );
    }

    /**
     * The fetch the paginator is expected to ask for: always one row past the limit of 2 the sources here use.
     *
     * @param array<string, mixed>|null $boundary
     *
     * @return array{boundary: array<string, mixed>|null, direction: NavigationDirection, maxResults: int}
     */
    private function fetchOf(?array $boundary, NavigationDirection $direction): array
    {
        return ['boundary' => $boundary, 'direction' => $direction, 'maxResults' => 3];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function token(string $direction, array $values): string
    {
        return $this->codec->encode(
            new Cursor(CursorCodec::CURRENT_VERSION, $direction, $values, InMemoryKeysetSource::FINGERPRINT),
        );
    }

    /**
     * @param callable(): mixed $paginate
     */
    private function assertRefused(InvalidCursorCause $expected, callable $paginate): void
    {
        try {
            $paginate();
            $this->fail('Expected InvalidCursor to be thrown.');
        } catch (InvalidCursor $invalidCursor) {
            $this->assertSame($expected, $invalidCursor->cause);
        }
    }
}
