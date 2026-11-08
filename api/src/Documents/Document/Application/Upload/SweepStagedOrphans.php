<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\UploadLimits;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The staging age sweep: deletes a staged object nobody owns. Ownership, not age, decides — age only nominates:
 * an object older than {@see UploadLimits::STAGING_ORPHAN_AGE_SECONDS} is deleted only when no document row
 * and no live (`Declared` or `Streaming`) intent holds its scope. A committed row owns its staged object even
 * when its promotion is late, so such an object stays for the roll-forward to move.
 *
 * The final namespace is out of reach by construction: this sweep names {@see StorageArea::Staging} on every
 * call, and a final object without a row is alarmed on by the scheduled report, never deleted.
 *
 * The limit counts deletions, not names: owned objects are passed over without spending it, so orphans behind
 * a long run of live uploads are still reached. A scope that fails is logged and passed over too.
 */
final readonly class SweepStagedOrphans
{
    public function __construct(
        private DocumentStorage $storage,
        private DocumentRepository $documents,
        private UploadIntentRepository $intents,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many staged objects this run deleted
     */
    public function sweep(int $limit): int
    {
        $deleted = 0;

        $candidates = $this->storage->scopesOlderThan(StorageArea::Staging, UploadLimits::STAGING_ORPHAN_AGE_SECONDS);

        foreach ($candidates as $scopeId) {
            if ($deleted >= $limit) {
                break;
            }

            try {
                if ($this->documents->existsForScope($scopeId) || $this->intents->hasLiveIntentForScope($scopeId)) {
                    continue;
                }

                $this->storage->delete(StorageArea::Staging, $scopeId);
                ++$deleted;
            } catch (Throwable $throwable) {
                $this->logger->error('A staged object could not be swept; the sweep goes on.', [
                    'scopeId' => $scopeId,
                    'exceptionClass' => $throwable::class,
                ]);
            }
        }

        return $deleted;
    }
}
