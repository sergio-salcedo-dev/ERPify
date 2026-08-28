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
 * **What `store()` guarantees, and when.** On return, reading the identifier back yields exactly the bytes
 * handed in — verified, not assumed. It does NOT promise that a partial object was never observable under
 * the identifier while the write was in flight: the write is direct, with no temporary object renamed into
 * place. That is a decision rather than an oversight, and it costs nothing here because nothing can observe
 * the window. The identifier is minted inside the upload use case and reaches no caller until its row has
 * committed, and `store()` refuses an identifier that already carries an object, so the only reader who
 * could look during the write is one holding an identifier nobody has handed out. An atomic rename would
 * buy that unobservable window at the price of a second filesystem operation, a temporary namespace to
 * garbage-collect and a failure mode — a rename that fails after a complete write — this contract has no
 * vocabulary for.
 *
 * **These failures do not carry an HTTP status, deliberately.** None of them implements an error-contract
 * marker, so today an uncaught one is an unhandled exception rather than a mapped response. A status is a
 * statement about a WIRE contract, and this module publishes no route: the only caller is the queue
 * consumer, where a marker changes nothing because the retry decision reads the exception rather than a
 * status. Minting one here would fix the mapping before the surface that has to honour it exists — and the
 * mapping the delivery story needs (absence to 404, transient to 5xx) is a property of that route reading
 * this vocabulary, which is exactly what the three verdicts above are for.
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
