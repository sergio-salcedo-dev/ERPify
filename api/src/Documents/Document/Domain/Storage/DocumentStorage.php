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
     * Moves the staged object of `$scopeId` into the final area, creating the destination exclusively: an
     * object already there is left untouched and the call fails. Not idempotent — a second call finds no
     * staged object — and the staged name is gone once it returns.
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
