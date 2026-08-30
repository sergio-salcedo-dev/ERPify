<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

use Throwable;

/**
 * Marker every storage failure implements, so a caller reads the verdict and the operation uniformly
 * instead of matching over concrete classes.
 *
 * Storage lives in its own namespace beside the port rather than in the module's `Domain/Exception/`,
 * which holds the pipeline's verdicts about supplied bytes. These say nothing about bytes: they describe
 * the substrate, and they are the vocabulary the delivery story maps onto HTTP statuses.
 */
interface ImageStorageException extends Throwable
{
    public function storageFailure(): StorageFailureCategory;

    public function operation(): StorageOperation;
}
