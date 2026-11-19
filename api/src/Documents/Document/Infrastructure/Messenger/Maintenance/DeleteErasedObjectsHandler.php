<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Erasure\DeleteErasedObjects;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One erased-object sweep tick. Idempotent: deleting an object that is not there is already satisfied, and the marker
 * is a compare-and-set.
 */
#[AsMessageHandler]
final readonly class DeleteErasedObjectsHandler
{
    private const int BATCH = 200;

    public function __construct(
        private DeleteErasedObjects $deleteErasedObjects,
    ) {
    }

    public function __invoke(DeleteErasedObjectsMessage $message): void
    {
        unset($message);

        $this->deleteErasedObjects->run(self::BATCH);
    }
}
