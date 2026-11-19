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
 * from a call that found nothing. A call that finds an image whose deletion is still in flight queues it again, so
 * the owner spaces its calls past the transport's retry horizon; {@see DeleteImage} is idempotent, so a duplicate is
 * harmless.
 *
 * One of the places that request a release, with {@see ImageAttachment} and {@see MaterialiseDerivative}:
 * `ImageDeletionRequestedPublisherGateTest` pins the set.
 */
final readonly class ReleaseDerivatives
{
    /** Ids read per page; one call pages through every derivative of the origin. */
    public const int PAGE = 100;

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
            $queued = 0;
            $after = null;

            do {
                $page = $this->imageRepository->idsByOrigin($origin, self::PAGE, $after);

                foreach ($page as $id) {
                    $this->eventBus->publish(new ImageDeletionRequested($id->toString()));
                    $after = $id;
                }

                $queued += \count($page);
            } while (self::PAGE === \count($page));

            return $queued;
        });
    }
}
