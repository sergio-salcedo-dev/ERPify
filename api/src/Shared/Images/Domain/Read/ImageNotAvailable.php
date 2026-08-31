<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Read;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * The image is not servable, and the two ways of getting here are deliberately one exception: the row is
 * absent, or the row is alive and its bytes are gone. From outside they are the same fact — nothing can be
 * served under this identifier — and separating them on the wire would let a caller distinguish "no such
 * image" from "an image exists whose bytes are lost", which is a statement about this deployment's
 * internal state that no client is owed.
 *
 * **It extends {@see DomainException} because that is the only way a marker is read.** `ProblemDetailsFactory`
 * resolves markers inside the `instanceof DomainException` arm of its `match`, so a storage exception given
 * a marker would fall through to the default arm and answer 500 — the very conflation the read route's
 * status table exists to prevent. Translation therefore happens above the port; the port's own three classes
 * are untouched.
 *
 * The `type` is left empty on purpose, so the factory answers the marker's default (`not-found`). This
 * module mints no `type` of its own: there is no domain distinction yet between this and any other 404 on
 * this route, and minting one before a consumer needs it fixes a wire contract nobody is reading.
 *
 * Nothing about the request is quoted in the title. An exception's text reaches `messenger_messages` through
 * `ErrorDetailsStamp` and the error reporter, and neither is reachable by any erasure path.
 */
final class ImageNotAvailable extends DomainException implements NotFound
{
    public static function forRequestedImage(): self
    {
        return new self(type: '', title: 'The requested image is not available.');
    }
}
