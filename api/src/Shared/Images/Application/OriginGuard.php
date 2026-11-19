<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\DerivativeOrigin;

/**
 * The owning context's answer to "may this resource receive a derivative now?", implemented by that context and passed
 * to {@see MaterialiseDerivative} on each call, as {@see ImageHolder} is to {@see ImageAttachment}, so this module
 * imports nothing from its consumers.
 *
 * It runs inside the transaction that inserts the derivative, and the lock it takes is the whole point: it must hold
 * the origin until that transaction ends and conflict with whatever the owner takes to end the resource's life, so a
 * derivative either commits before that end (and the owner's release finds it) or is refused after it.
 */
interface OriginGuard
{
    /**
     * Locks the origin for the rest of the surrounding transaction and answers whether it may receive a derivative.
     * An origin that does not exist answers false; it never raises for that.
     */
    public function admits(DerivativeOrigin $origin): bool;
}
