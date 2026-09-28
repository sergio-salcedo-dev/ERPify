<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Throwable;

/**
 * An administrative act refused because its target is the acting administrator's own identity.
 *
 * It classifies a refusal for the audit trail and has no bearing on the wire: every implementer is already a
 * `Conflict` (409) with a `type()` of its own, and the error contract maps it by that marker, never by this one.
 * What it buys is a single, exhaustive place for the `security` row: the listener that records these refusals
 * matches the interface rather than a list of classes, so a fifth self-refusal is recorded by implementing it.
 * Forgetting to implement it is the one way to be refused in silence, which is why `SelfTargetedRefusalMarkerGateTest`
 * holds every `Self*Forbidden` domain exception under `src` to it.
 *
 * A refusal of this kind is precisely what a stolen administrator session looks like when it tries to turn the
 * users surface on its own identity, which is why it is worth a row even though nothing changed.
 */
interface SelfTargetedActForbidden extends Throwable
{
    /**
     * The refusal's problem `type`, a closed constant of the implementer — the only detail of the refusal the
     * audit row carries besides the route.
     */
    public function type(): string;
}
