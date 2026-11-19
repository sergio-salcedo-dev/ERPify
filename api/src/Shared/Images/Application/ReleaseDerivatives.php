<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Queues the deletion of every image that derives from one origin. The owner decides WHEN — this module decides
 * nothing about a resource's life (invariant 1) — and calls it after its own end-of-life has committed, never inside
 * that transaction (invariant 4).
 *
 * **What to delete is re-derived on every call, never remembered.** The ids are read from the rows that exist now, so
 * a request lost to a dead letter is issued again by the next call, and the owner learns that the origin is clear only
 * from a call that found nothing. A call that finds an image whose deletion is already in flight queues it again;
 * {@see DeleteImage} is idempotent, so the duplicate is harmless.
 *
 * The second place that requests a release, beside {@see ImageAttachment}: `ImageDeletionRequestedPublisherGateTest`
 * pins the set.
 */
final readonly class ReleaseDerivatives
{
    /** Ids queued per call; an origin holding more is finished by the following calls. */
    public const int BATCH = 100;

    public function __construct(
        private ImageRepository $imageRepository,
        private TransactionManager $transactionManager,
        private EventBus $eventBus,
    ) {
    }

    /**
     * @return int how many releases this call queued; zero means no image derives from the origin any more
     */
    public function of(DerivativeOrigin $origin): int
    {
        return $this->transactionManager->transactional(function () use ($origin): int {
            $ids = $this->imageRepository->idsByOrigin($origin, self::BATCH);

            foreach ($ids as $id) {
                $this->eventBus->publish(new ImageDeletionRequested($id->toString()));
            }

            return \count($ids);
        });
    }
}
