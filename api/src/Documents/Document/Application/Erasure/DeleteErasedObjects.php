<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Application\Upload\SweepBudget;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasedObject;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single owner of the sealed object of an `Erased` row: deletes the staged object first and then the final one,
 * tolerates a missing one on each, and records the row done only when neither exists. The order is the point. A
 * writer's post-commit rename can race an erasure and recreate the final object from the staged one; deleting the
 * staged name first means a promotion that has not happened yet can no longer happen, and one that already did leaves a
 * final object this run then deletes.
 *
 * It runs after the erasure committed and never inside it, so a failed delete never rolls the erasure back: the row
 * stays pending, the next tick tries it again, and an object still there an hour after its `Erased` custody row raises
 * the alarm. It is the one deletion in the final namespace besides the replay's, and the schedule that runs it is
 * stopped while a restore holds the stack.
 *
 * Bounded like every other sweep ({@see SweepBudget}): one item that cannot be processed is logged by its document id
 * and passed over, never the end of the batch, and a run of failures in a row ends it with one line.
 */
final readonly class DeleteErasedObjects
{
    private const int ALARM_SCAN_LIMIT = 1_000;

    private const int IDS_PER_LINE = 20;

    public function __construct(
        private DocumentErasures $erasures,
        private DocumentStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many rows this run confirmed done
     */
    public function run(int $limit): int
    {
        $budget = new SweepBudget($limit);
        $after = null;

        while (!$budget->exhausted()) {
            $page = $this->erasures->awaitingObjectDeletion($limit, $after);

            foreach ($page as $erased) {
                $after = $erased->documentId;
                $this->deleteWithin($budget, $erased);

                if ($budget->exhausted()) {
                    break;
                }
            }

            if (\count($page) < $limit) {
                break;
            }
        }

        if ($budget->stoppedOnFailures()) {
            $this->logger->error('The erased-object sweep stopped after consecutive failures.', [
                'failures' => SweepBudget::MAX_CONSECUTIVE_FAILURES,
                'deleted' => $budget->finishedCount(),
            ]);
        }

        $this->alarmOnOverdueObjects();

        return $budget->finishedCount();
    }

    private function deleteWithin(SweepBudget $budget, ErasedObject $erased): void
    {
        try {
            $this->deleteOne($erased) ? $budget->finished() : $budget->passed();
        } catch (Throwable $throwable) {
            $this->logger->error('The sealed object of an erased document could not be deleted; the batch goes on.', [
                'documentId' => $erased->documentId,
                'exceptionClass' => $throwable::class,
            ]);
            $budget->failed();
        }
    }

    private function deleteOne(ErasedObject $erased): bool
    {
        // Staging first, then final: the direction a promotion moves the object.
        $this->storage->delete(StorageArea::Staging, $erased->scopeId);
        $this->storage->delete(StorageArea::Final, $erased->scopeId);

        $stillThere = $this->storage->has(StorageArea::Staging, $erased->scopeId)
            || $this->storage->has(StorageArea::Final, $erased->scopeId);

        if ($stillThere) {
            return false;
        }

        return $this->erasures->markObjectDeleted($erased->documentId);
    }

    private function alarmOnOverdueObjects(): void
    {
        $overdue = $this->erasures->objectsOverdue(ErasureLimits::OBJECT_ALARM_SECONDS, self::ALARM_SCAN_LIMIT);

        if ([] === $overdue) {
            return;
        }

        $this->logger->error('Sealed objects of erased documents are older than the bound.', [
            'count' => \count($overdue),
            'boundSeconds' => ErasureLimits::OBJECT_ALARM_SECONDS,
            'documentIds' => \array_slice($overdue, 0, self::IDS_PER_LINE),
        ]);
    }
}
