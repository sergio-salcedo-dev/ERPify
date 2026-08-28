<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Storage;

use Erpify\Shared\Images\Domain\ImageId;

/**
 * The canonical bytes of an image, addressed by identity alone.
 *
 * The surface is exactly these three operations, and the semantics of absence differ between them — a
 * distinction that must be read here rather than inferred, because implementing absence as an exception
 * of `delete()` would satisfy the read contract while breaking the delete one:
 *
 * - `store()` — the identifier must not already carry an object; reuse is corruption, not idempotence.
 * - `read()` — a confirmed absence is a distinguishable outcome ({@see ImageBytesNotFound}), separate
 *   from a substrate failure. The delivery story maps the first to 404 and the second to 5xx, and cannot
 *   invent the distinction: it is provided here.
 * - `delete()` — a confirmed absence is SUCCESS. The operation is idempotent toward absence and never
 *   toward failure, because the consumer retries on failure and must not be told an erasure happened.
 *
 * **The conservative default is part of the contract.** Absence is declared only when it is demonstrable;
 * anything that fails to establish existence is a failure, never absence. Erring toward "not found" turns
 * a misconfiguration into a 404 and, on the delete path, into a confirmed erasure of bytes still present.
 * Erring toward failure costs a retry. This is not inherited from the storage library — it is imposed on
 * whatever library implements it.
 *
 * No method returns a URL, and none accepts or returns a path or a storage key. Where the bytes live, and
 * how they are addressed physically, is the adapter's business; delivery belongs to the read story.
 *
 * A future consumer that references an image belonging to a natural person declares that reference in
 * `api/.person-reference-policy`, at its own column. An {@see ImageId} never determines by itself whether
 * it denotes personal data: this module holds no owner and no classification, so the consuming context is
 * the only place that knows.
 */
interface ImageStorage
{
    /**
     * @throws ImageStorageFailed      when the identifier already carries an object, or the substrate is
     *                                 permanently unusable
     * @throws ImageStorageUnavailable when the substrate failed in a retryable way
     */
    public function store(ImageId $id, string $bytes): void;

    /**
     * @throws ImageBytesNotFound      when the object is demonstrably absent
     * @throws ImageStorageFailed      when the substrate is permanently unusable
     * @throws ImageStorageUnavailable when the substrate failed in a retryable way
     */
    public function read(ImageId $id): string;

    /**
     * Returns normally when the object is gone, including when it was already absent.
     *
     * @throws ImageStorageFailed      when existence cannot be established, or the substrate is
     *                                 permanently unusable
     * @throws ImageStorageUnavailable when the substrate failed in a retryable way
     */
    public function delete(ImageId $id): void;
}
