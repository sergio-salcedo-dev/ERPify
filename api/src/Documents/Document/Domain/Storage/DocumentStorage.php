<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

/**
 * Where the sealed bytes of a document live. `Documents` has its own storage port, written as a stream: no
 * generic port spans it and `Shared\Images\Domain\Storage\ImageStorage`, because the two contracts disagree on
 * the shape — evidence is written as a stream and sealed and must not be transformed, whereas an image port
 * promises a retrievable blob over content it was free to re-encode.
 *
 * Every object is addressed by the document's scope id and the {@see StorageArea} it is in; the domain never
 * knows a physical path. The scope id is the random identifier of the document's encryption scope, so the key
 * names nothing about the document and promotion needs no re-seal.
 *
 * **What the port does not promise.** It declares no WORM guarantee: an adapter cannot enforce one on a local
 * filesystem, so tampering with a sealed object is detected at read by authentication, never prevented here.
 * Atomicity of {@see promote()} is claimed only for a staging area and a final area on ONE filesystem, and the
 * adapter's own test probes that rather than assuming it. Durability policy (fsync of the file and of its
 * directory), the boot probe and the sweeps that reap abandoned objects belong to the stories that own the
 * upload flow, not to this contract.
 *
 * Failures are typed ({@see DocumentStorageException}) and carry neither the key nor the underlying library's
 * message, both of which derive from the scope id.
 *
 * **Durability.** {@see stageWith()} syncs the staged file and its directory before it returns, and
 * {@see promote()} syncs the final directory after the rename, so an object the caller was told is staged or
 * final survives a crash of the host.
 */
interface DocumentStorage
{
    /**
     * Streams `$stream` to the staging key of `$scopeId` and leaves it there.
     *
     * Refuses a key that already carries an object — it never overwrites, so a reused scope cannot destroy one
     * document's bytes under another's name — and removes the partial object a failed write leaves behind.
     * The stream is read to its end and left open: closing it is the caller's.
     *
     * @param resource $stream readable, positioned where the bytes to store begin
     *
     * @throws ObjectAlreadyExists   when the staging key is taken
     * @throws DocumentStorageFailed when the substrate refuses or the write cannot be completed
     */
    public function stage(string $scopeId, $stream): void;

    /**
     * Opens the staging key of `$scopeId` exclusively and hands its sink to `$writer`, which writes the whole
     * object; then syncs the file and the staging directory and closes it. The sink is the one way bytes reach
     * staging as they are produced — a stream sealed as it is read has no readable stream to hand
     * {@see stage()} without buffering it whole.
     *
     * Whatever `$writer` throws, the partial object is removed and the throwable propagates unchanged.
     *
     * @param callable(resource): void $writer receives a writable, blocking stream that supports `fflush()`
     *
     * @throws ObjectAlreadyExists   when the staging key is taken
     * @throws DocumentStorageFailed when the substrate refuses, or the object cannot be synced or closed
     */
    public function stageWith(string $scopeId, callable $writer): void;

    /**
     * Whether `$area` holds an object for `$scopeId`. Only a demonstrable absence answers false; a path that
     * cannot be examined is a failure.
     *
     * @throws DocumentStorageFailed
     */
    public function has(StorageArea $area, string $scopeId): bool;

    /**
     * The scope ids of the objects in `$area` last modified at least `$olderThanSeconds` ago, at most `$limit`
     * of them, in no promised order. What a sweep walks; it says nothing about whether a row owns each one.
     *
     * @throws DocumentStorageFailed
     *
     * @return list<string>
     */
    public function scopesOlderThan(StorageArea $area, int $olderThanSeconds, int $limit): array;

    /**
     * Moves the staged object of `$scopeId` into the final area, creating the destination exclusively: an
     * object already there is left untouched and the call fails. Not idempotent — a second call finds no
     * staged object — and the staged name is gone once it returns, with the final directory synced.
     *
     * @throws ObjectNotFound        when there is no staged object
     * @throws ObjectAlreadyExists   when the final area already holds an object for the scope
     * @throws DocumentStorageFailed
     */
    public function promote(string $scopeId): void;

    /**
     * Opens the final object of `$scopeId` for reading.
     *
     * The caller closes the stream. An absent object is a typed outcome, never an empty stream.
     *
     * @throws ObjectNotFound        when the final area holds no object for the scope
     * @throws DocumentStorageFailed
     *
     * @return resource
     */
    public function open(string $scopeId);

    /**
     * Removes the object of `$scopeId` from one area. Idempotent: an object that is not there is already
     * deleted, and only a demonstrable absence counts — a path that cannot be traversed is a failure, not a
     * confirmed deletion.
     *
     * @throws DocumentStorageFailed
     */
    public function delete(StorageArea $area, string $scopeId): void;
}
