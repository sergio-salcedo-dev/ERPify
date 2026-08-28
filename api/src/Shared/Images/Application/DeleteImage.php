<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Releases an image's bytes and then its row, in that order.
 *
 * **Who decides what.** The consuming aggregate decides an image is no longer needed and publishes the
 * request after its own commit; this module owns the physical removal and nothing else. It never decides an
 * image's lifecycle, and there is no ownership or reference counting here to consult — so it cannot, and
 * does not, refuse a request because someone else might still hold the identifier.
 *
 * **Why bytes first.** The two steps are not atomic and no transaction spans a database and a filesystem,
 * so the only thing available is an order whose every intermediate state is retryable. Bytes first leaves,
 * on a failure in between, a row with no object — which the next delivery resolves, because deleting an
 * absent object is a success. The reverse order would leave an object with no row referencing it, and this
 * module keeps no record that could ever find it again.
 *
 * **All four states, since delivery is at-least-once and two workers can race:**
 * row present + object present → both removed;
 * row absent + object present → the object is removed anyway, which is what the accepted orphans of the
 * upload path make inevitable;
 * row absent + object absent → nothing happens, and that is success;
 * a failure while READING the row → raised, never mistaken for the row being absent.
 * A storage failure raises according to its own class. Two consecutive runs leave the same final state and
 * neither resurrects the row.
 */
final readonly class DeleteImage
{
    public function __construct(
        private ImageStorage $imageStorage,
        private ImageRepository $imageRepository,
        private TransactionManager $transactionManager,
    ) {
    }

    public function delete(ImageId $id): void
    {
        $this->imageStorage->delete($id);

        // `null` here is a confirmed absence, never a lookup that failed — the port raises for that, which
        // is what keeps a broken connection from being recorded as an erasure already done.
        $image = $this->imageRepository->findById($id);

        if (null === $image) {
            return;
        }

        $this->transactionManager->transactional(function () use ($image): void {
            $this->imageRepository->remove($image);
        });
    }
}
