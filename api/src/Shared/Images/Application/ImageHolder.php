<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\ImageId;

/**
 * The port an aggregate that holds one image implements, so {@see ImageAttachment} can set, replace and release
 * that image without knowing what the aggregate is. The holder owns the column; this module owns the protocol.
 *
 * Both methods are called inside a transaction the service opened, and only there: {@see attach()} always after
 * {@see lockAndRead()} in the attach or remove transaction, and {@see lockAndRead()} ALONE in the compensation
 * transaction that follows a failed attach, with no `attach()`. An implementation never opens a transaction of its
 * own, never publishes an event about the image, and never touches the image row or its bytes.
 */
interface ImageHolder
{
    /**
     * Loads the owner `FOR UPDATE` and returns the image id it holds, or null when it holds none. The lock is what
     * makes the id read here the id the next {@see attach()} replaces: two writers on one owner take it in turn, so
     * each replaced id is released once.
     *
     * An owner that no longer exists raises the owner's own not-found exception — the one its routes already answer
     * 404 with — rather than answering null, because "holds nothing" and "is gone" are different outcomes and the
     * second must store nothing. That exception MUST implement
     * `Erpify\Shared\ErrorContract\Domain\Exception\NotFound`: the compensation catches that marker and nothing
     * else, so any other exception makes the compensation fail and the new id is not released.
     */
    public function lockAndRead(string $ownerId): ?ImageId;

    /**
     * Points the owner it just locked at `$imageId`, or at nothing when null.
     */
    public function attach(string $ownerId, ?ImageId $imageId): void;
}
