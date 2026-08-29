<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Messenger;

use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\ImageId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes the deletion request off the queue and runs the use case. A thin adapter: it converts the
 * envelope's aggregate id back into the domain identity and delegates. All ordering, idempotence and
 * failure semantics live in {@see DeleteImage}.
 *
 * Being a queue consumer rather than a Doctrine lifecycle listener is the whole point of the seam. A
 * `postRemove` listener would run the physical deletion inside the owner's own transaction, so a storage
 * failure would roll back the owner's business write and leave a live reference over destroyed bytes.
 */
#[AsMessageHandler]
final readonly class DeleteImageOnDeletionRequested
{
    public function __construct(private DeleteImage $deleteImage)
    {
    }

    public function __invoke(ImageDeletionRequested $request): void
    {
        $this->deleteImage->delete(ImageId::fromString($request->aggregateId()));
    }
}
