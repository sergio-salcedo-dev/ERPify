<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Storage;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\DocumentScopeId;
use Throwable;

/**
 * Proves, before a container takes traffic, that the deployed storage can do what every upload will ask of it:
 * create an object in staging, sync it, move it into the final namespace and remove it. It refuses on any
 * failure, and the entrypoint refuses to start the container with it.
 *
 * Probing rather than comparing device ids is the point: `st_dev` equality is not proof, because a move fails
 * with `EXDEV` across two mount points even when both are one filesystem, and every promotion would then fail
 * after its commit — leaving filed documents nobody can scan. The probe runs the same port methods the upload
 * runs, on a scope id minted for the probe and never written to the keystore.
 */
final readonly class StorageBootProbe
{
    public function __construct(
        private DocumentStorage $storage,
    ) {
    }

    /**
     * @throws Throwable whatever the storage refused, after the probe object is cleaned up as far as possible
     */
    public function probe(): void
    {
        $scopeId = DocumentScopeId::mint();

        try {
            $this->storage->stageWith($scopeId, static function ($sink): void {
                \fwrite($sink, 'erpify-documents-storage-probe');
            });
            $this->storage->promote($scopeId);
            $this->storage->delete(StorageArea::Final, $scopeId);
        } catch (Throwable $throwable) {
            foreach (StorageArea::cases() as $area) {
                try {
                    $this->storage->delete($area, $scopeId);
                } catch (Throwable) {
                    // The failure being reported is the one that matters.
                }
            }

            throw $throwable;
        }
    }
}
