<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Infrastructure\Controller;

use Erpify\Shared\Images\Application\CanonicalImageBytes;
use Erpify\Shared\Images\Application\CanonicalImageFinder;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Infrastructure\Http\HttpCacheValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/images/{imageId}` — the canonical bytes of an image, addressed by identity alone.
 *
 * **This is an infrastructure proof, not a product API.** It establishes that bytes are retrievable across
 * the `Images` boundary. It grants no ownership and decides no semantic authorization: any authenticated
 * caller may read any image, because the module holds no owner and cannot tell a company logo from a
 * person's avatar. The frontier is therefore provisional, and belongs to the epic that wires the first real
 * consumer.
 *
 * **The route name is load-bearing.** Generic activity auditing is opt-OUT: `AuditPolicy` records every
 * successful `GET` under `/api/` unless the route name matches one of its five non-business shapes, and
 * `shared_` is the one that fits object serving. Rename this route and every read starts writing an
 * `audit_log` row, with no gate going red — the epic's "zero audit rows" decision is a property of this
 * string.
 *
 * **`{imageId}` carries no `requirements` on purpose.** Constraining it to a UUID shape would have the
 * router answer 404 for a malformed identifier, conflating "you asked wrongly" with "there is nothing
 * there". It reaches the controller and `ImageId::fromString()` answers 400 `invalid-uuid`.
 *
 * **No `#[IsGranted]`.** The firewall's `^/api` catch-all is the whole boundary of this slice; there is no
 * relation yet to vote on, so a voter here would be a permission invented ahead of the thing it governs.
 *
 * What this slice does not implement, said rather than left to be discovered: `Range` is ignored and no
 * `Accept-Ranges` is advertised, because announcing a capability that is not there is worse than silence;
 * `If-Modified-Since` is ignored and no `Last-Modified` is emitted, though `Image::createdAt()` exists,
 * because a second validation axis is a second thing to keep true; `If-Match` is not evaluated, so a
 * mismatching one does not answer 412. `HEAD` works because the router pairs it with `GET` and the kernel
 * drops the body — at the full cost of the read and the digest, exactly like a `200`.
 */
#[Route('/images/{imageId}', name: 'shared_image_get', methods: ['GET'])]
final readonly class ImageGetController
{
    /**
     * Chosen over `max-age=31536000`, which is what the epic wrote and what the retired helper emitted. The
     * identifier is immutable — this module has no replace operation, so the representation behind an
     * `ImageId` never changes — but its contract is RELIABLE DELETION, and deletion is a different
     * life-cycle event from mutation. At a year, a viewer keeps serving an image for a year after its bytes
     * and its row are erased, with no request ever reaching a server that could answer 404. In a module
     * that cannot tell a logo from a person's face, that is indefensible.
     *
     * One hour is a PRIOR, not a measurement: there is no declared deletion SLA and no consumer whose reuse
     * window could be measured, so nothing arbitrates between a year and an hour but prudence. Revisit on
     * the first of the two. If that SLA turns out to be zero, the answer is `no-store` rather than a smaller
     * number — no finite window satisfies it.
     */
    private const int CACHE_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private CanonicalImageFinder $finder,
        private HttpCacheValidator $cacheValidator,
    ) {
    }

    /**
     * The read happens BEFORE the conditional branch, and that ordering is the whole point of it.
     *
     * A `304` claims the copy the client holds is still good, so it may only be answered while the object is
     * still retrievable — otherwise a viewer keeps serving bytes this deployment has already lost. The
     * retired controller gated that on an existence predicate; this port has none, and adding one would
     * reopen a decision it settled deliberately (its internal predicate RAISES when existence cannot be
     * established, which is what keeps a permission fault from being reported as an erasure).
     *
     * So the gate is the verified read itself. The cost is declared rather than hidden: a `304` performs
     * exactly the same I/O and the same SHA-256 as a `200`, and saves only the body on the wire.
     */
    public function __invoke(Request $request, string $imageId): Response
    {
        $image = $this->finder->find(ImageId::fromString($imageId));

        if ($this->cacheValidator->isNotModified($request, $image->digest)) {
            return $this->respondNotModified($image->digest);
        }

        return $this->respondWithBytes($image);
    }

    private function respondWithBytes(CanonicalImageBytes $image): Response
    {
        $response = new Response($image->bytes);

        // The canonical media type off the row, never sniffed from the bytes and never echoed from a request
        // header. The allowlist of types lives at the producer, so this second reader inherits that
        // discipline rather than restating it — `Image` itself only guarantees the value is non-blank.
        $response->headers->set('Content-Type', $image->mediaType);

        // Measured on what is actually served rather than taken from `Image::byteSize()`. The digest
        // verification makes the two equal, and reading the served string makes a mismatch impossible by
        // construction instead of true by invariant.
        $response->headers->set('Content-Length', (string) \strlen($image->bytes));

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $this->applyCacheHeaders($response, $image->digest);

        return $response;
    }

    /**
     * `setNotModified()` keeps whatever headers are already on the response and strips `Content-Type` and
     * `Content-Length`. Building the `304` on a bare response would therefore emit it with neither a
     * validator nor a freshness directive — satisfying every other rule here while breaking the conditional
     * loop the double gate pays a full read to sustain, because the client would have nothing to send back.
     */
    private function respondNotModified(string $digest): Response
    {
        $response = new Response();
        $this->applyCacheHeaders($response, $digest);
        $response->setNotModified();

        return $response;
    }

    /**
     * `private` because the response is authenticated and no shared cache may hold it.
     *
     * `immutable` earns its place on its own terms rather than on the year the epic paired it with: over a
     * bare `max-age` it suppresses the revalidation some browsers fire on reload WITHIN the window. Small,
     * and not nothing.
     *
     * **What leaves the process is not this string.** `HeaderBag` serializes cache directives in alphabetical
     * order, and because the `main` firewall is stateful, `AbstractSessionListener` rewrites the header on
     * `kernel.response`, adding `must-revalidate` and an `Expires`. That rewrite is ACCEPTED, not escaped
     * with `NO_AUTO_CACHE_CONTROL_HEADER`: `immutable` governs the fresh phase and `must-revalidate` the
     * stale one, so they compose rather than contradict, and opting out would make the privacy residual
     * worse rather than better — a cached copy outliving an erasure is the thing to shorten, and
     * `must-revalidate` shortens it.
     */
    private function applyCacheHeaders(Response $response, string $digest): void
    {
        $response->setPrivate();
        $response->setMaxAge(self::CACHE_MAX_AGE_SECONDS);
        $response->headers->addCacheControlDirective('immutable');
        $response->setEtag($digest);
    }
}
