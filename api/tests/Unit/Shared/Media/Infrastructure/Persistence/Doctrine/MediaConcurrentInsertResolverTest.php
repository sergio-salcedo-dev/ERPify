<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Media\Infrastructure\Persistence\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\ConcurrentMediaWinnerMissingException;
use Erpify\Shared\Media\Infrastructure\Persistence\Doctrine\MediaConcurrentInsertResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MediaConcurrentInsertResolver::class)]
#[CoversClass(ConcurrentMediaWinnerMissingException::class)]
final class MediaConcurrentInsertResolverTest extends TestCase
{
    private const string MEDIA_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CONTENT_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testReturnsTheWinnerOnTheFirstRefetchAfterResettingTheManager(): void
    {
        $winner = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');
        $repository = new RefetchingMediaRepository(winner: $winner);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->once())->method('resetManager');

        $resolver = new MediaConcurrentInsertResolver($managerRegistry);
        $result = $resolver->resolveWinner(self::CONTENT_HASH, $repository);

        $this->assertSame($winner, $result);
        $this->assertSame(1, $repository->findCalls);
    }

    public function testRetriesTheRefetchWhenTheWinnerIsNotYetVisibleOnTheFirstReQuery(): void
    {
        // The winning insert commits just after ours rolled back, so it is invisible to the first
        // post-reset re-query under READ COMMITTED and only appears on the retry.
        $winner = Media::create(self::MEDIA_ID, self::CONTENT_HASH, 'image/png', 4, 'PNG.');
        $repository = new RefetchingMediaRepository(winner: $winner, winnerVisibleFromFind: 2);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->exactly(2))->method('resetManager');

        $resolver = new MediaConcurrentInsertResolver($managerRegistry);
        $result = $resolver->resolveWinner(self::CONTENT_HASH, $repository);

        $this->assertSame($winner, $result);
        $this->assertSame(2, $repository->findCalls, 'first re-query misses, retry hits');
    }

    public function testThrowsTaggingTheContentHashWhenTheWinnerCannotBeRefetched(): void
    {
        // Every post-reset re-query misses: the winning row is an unrecoverable inconsistency.
        $resolver = new MediaConcurrentInsertResolver($this->createStub(ManagerRegistry::class));

        $this->expectException(ConcurrentMediaWinnerMissingException::class);
        $this->expectExceptionMessageMatches('/' . self::CONTENT_HASH . '/');

        $resolver->resolveWinner(self::CONTENT_HASH, new RefetchingMediaRepository(winner: null));
    }

    public function testExhaustsTheBoundedRetryBeforeGivingUpWhenTheWinnerStaysAbsent(): void
    {
        $repository = new RefetchingMediaRepository(winner: null);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->exactly(2))->method('resetManager');
        $resolver = new MediaConcurrentInsertResolver($managerRegistry);

        try {
            $resolver->resolveWinner(self::CONTENT_HASH, $repository);
            $this->fail('Expected ConcurrentMediaWinnerMissingException to be thrown');
        } catch (ConcurrentMediaWinnerMissingException) {
            // Expected — the resolver gives up only after the bounded retry is exhausted.
        }

        $this->assertSame(2, $repository->findCalls, 'the bounded retry must be exhausted before giving up');
    }
}
