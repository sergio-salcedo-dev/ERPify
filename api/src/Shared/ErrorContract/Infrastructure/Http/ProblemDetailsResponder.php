<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Infrastructure\Http;

use Erpify\Shared\ErrorContract\Application\ProblemDetails;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapter from {@see ProblemDetails} to a Symfony {@see Response} carrying the RFC 9457 wire envelope:
 * - body: `json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`
 * - status: `$problemDetails->status`
 * - `Content-Type: application/problem+json` (no charset suffix — the media type is mandated UTF-8)
 * - `Cache-Control: no-store`
 *
 * Parallel to {@see Responder\JsonResponder}; intentionally does NOT implement
 * {@see Responder\ResponderInterface} because that interface's input is the success-path
 * `Erpify\Shared\Kernel\Application\Result` value object. Forcing them to share a method
 * signature would either leak error semantics into the success-path DTO or widen the
 * interface to `respond(object): Response`, weakening the type guarantee for every existing
 * caller. Keeping them as parallel-named classes preserves both contracts.
 *
 * Uses raw {@see Response}, not {@see \Symfony\Component\HttpFoundation\JsonResponse},
 * because `JsonResponse` hard-codes `Content-Type: application/json` (with charset) and
 * re-encodes via its own pipeline — we already own the encoding via {@see ProblemDetails::toArray()}.
 */
final readonly class ProblemDetailsResponder
{
    public function respond(ProblemDetails $problemDetails): Response
    {
        $body = \json_encode(
            $problemDetails->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return new Response(
            $body,
            $problemDetails->status,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
