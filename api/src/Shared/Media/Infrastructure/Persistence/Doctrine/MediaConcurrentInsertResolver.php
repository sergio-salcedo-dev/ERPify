<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Persistence\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Exception\ConcurrentMediaWinnerMissingException;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;

/**
 * Recovers the canonical media row after an insert lost the `media_content_hash_uniq` race: the row
 * a concurrent request committed first is the winner, so this re-fetches it on a freshly reset
 * entity manager. Split out of {@see DoctrineMediaRepository} so the recovery policy (manager reset
 * + bounded retry) stays unit-testable without a database.
 */
final readonly class MediaConcurrentInsertResolver
{
    /**
     * Re-fetch attempts for the concurrent winner. Under READ COMMITTED the winning insert's row
     * may not be visible at the first re-query (its transaction can commit just after ours rolled
     * back on the unique violation); a second attempt distinguishes "not yet visible" from
     * "genuinely absent" before escalating to an unrecoverable 500.
     */
    private const int WINNER_REFETCH_ATTEMPTS = 2;

    public function __construct(private ManagerRegistry $managerRegistry)
    {
    }

    /**
     * @param MediaRepository $repository re-queries the winner; the failed flush closed the entity
     *                                    manager, and {@see ManagerRegistry::resetManager()} re-opens
     *                                    it in place, so the already-injected repository transparently
     *                                    binds to the fresh manager
     *
     * @throws ConcurrentMediaWinnerMissingException when the winner stays absent across all attempts
     */
    public function resolveWinner(string $contentHash, MediaRepository $repository): Media
    {
        for ($attempt = 0; $attempt < self::WINNER_REFETCH_ATTEMPTS; ++$attempt) {
            $this->managerRegistry->resetManager();

            $winner = $repository->findByContentHash($contentHash);

            if ($winner instanceof Media) {
                return $winner;
            }
        }

        throw ConcurrentMediaWinnerMissingException::forContentHash($contentHash);
    }
}
