<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Application\Read\ReadDocument;
use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /api/v1/documents/{id}/content`: the original of a `Ready` document, byte for byte, as an attachment. Gated by
 * `document.download`, which only the ADMIN wildcard carries; a conscious frontier decision, not a public route.
 *
 * The route is always registered (the route manifest, the credential-proof classification and the voter hold) and
 * this controller answers 410 `gone` while the erasure ledger's override is set, whatever the switch says, and 404
 * `not-found` while `documents.read_enabled` is false. Both are decided after the firewall and the voter, so only an
 * authorised caller learns the route's state, and before any lookup.
 *
 * **Every refusal and every integrity failure precedes the first byte** ({@see ReadDocument}), so a client that checks
 * `response.ok` before saving can show the problem. The headers make the body inert on the origin that also serves the
 * PWA: a fixed `application/octet-stream` (the row holds no media type and none is taken on trust), `nosniff`, a
 * sandboxing CSP, `same-origin` resource policy, no cache, and a `Content-Disposition` with no `filename` parameter
 * because the sealed filename never leaves in a header. `Range` is ignored, no validator is emitted.
 *
 * The transfer holds one PHP thread for its whole duration, and PHP's wall-clock time limit would cut a slow client
 * short, so the limit is re-armed at every chunk ({@see self::STALL_BUDGET_SECONDS}): bounded per stall, never in
 * total.
 *
 * The route sets `_audit_canonical`: the use case writes the row, so the generic access-log hook must not add a
 * thinner one. Its name must not start with `shared_`, the prefix that policy skips.
 */
#[Route(
    '/documents/{id}/content',
    name: UploadRoutes::READ,
    defaults: ['_audit_canonical' => true],
    methods: ['GET'],
)]
#[IsGranted(DocumentPermission::DOWNLOAD)]
final readonly class ReadDocumentController
{
    /**
     * A STALL budget, not a total: PHP's time limit is wall-clock and counts the seconds a slow client keeps the socket
     * full, so it is re-armed at every chunk — a client that stops reading for this long is dropped, a steady slow one
     * is not.
     */
    private const int STALL_BUDGET_SECONDS = 60;

    public function __construct(
        private ReadDocument $readDocument,
        private ErasureLedgerOverride $ledgerOverride,
        private UploadTimeBudget $timeBudget,
        #[Autowire(param: 'documents.read_enabled')]
        private bool $readEnabled,
    ) {
    }

    public function __invoke(Request $request, string $id): StreamedResponse
    {
        // The router pairs a GET route with HEAD, whose body is dropped after the fact: both decrypt passes would run
        // and a row would record a read that delivered nothing. Refused before either.
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        // First, whatever the switch says: while a restore proceeds without the erasure ledger, nothing is served.
        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        if (!$this->readEnabled) {
            throw new NotFoundHttpException();
        }

        // Verification reads the whole original and the transfer may stream for minutes, and nothing below writes to
        // the session: release its lock now, or every other request of the same caller waits behind this one.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $transfer = $this->readDocument->open($id);

        $timeBudget = $this->timeBudget;
        $response = new StreamedResponse(static function () use ($transfer, $timeBudget): void {
            $timeBudget->arm(self::STALL_BUDGET_SECONDS);
            $transfer->sendTo(static function (string $bytes) use ($timeBudget): void {
                echo $bytes;
                \flush();
                $timeBudget->arm(self::STALL_BUDGET_SECONDS);
            });
        });

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Length', (string) $transfer->sizeBytes);
        $response->headers->set('Content-Disposition', 'attachment');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
