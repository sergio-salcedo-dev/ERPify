<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Mints a UUIDv7 `correlation-id` per main request (kernel.request, priority `PRIORITY = 1024`)
 * and writes the same value back as the `X-Correlation-Id` response header on every main
 * response (kernel.response, priority `RESPONSE_PRIORITY = -1024`). The value flows end to end:
 * inbound `X-Correlation-Id` (when canonical lowercase UUIDv7) → `_correlation_id` request
 * attribute → `X-Correlation-Id` response header.
 *
 * Inbound headers are propagated verbatim only when (a) the header is sent exactly once and
 * (b) the value matches a strict lowercase UUIDv7 pattern (RFC 9562 §6.10). Any other shape —
 * uppercase, wrong version bits, wrong variant bits, extra garbage, embedded CRLF, lone
 * trailing `\n`, leading/trailing whitespace, embedded NUL byte, length mismatch, empty string,
 * or multiple `X-Correlation-Id` headers — is rejected and a fresh UUIDv7 is minted. The
 * pattern uses `\A…\z` anchors (not `^…$`) so PHP's default `$`-before-final-`\n` semantics
 * cannot leak a trailing newline through.
 *
 * On the response side, the request attribute is **re-validated** with the same regex before
 * being written to the header — defense-in-depth against any listener that may have tampered
 * with `_correlation_id` between kernel.request and kernel.response. The header write
 * overwrites any pre-existing value.
 *
 * Sub-requests (ESI fragments, forwards) are skipped on both events — only the main request
 * mints, only the main response carries the header.
 *
 * Worker-mode safe: `final readonly`, no constructor, no instance / static state. Pinned by
 * `testListenerHasNoConstructorAndIsFinalReadonly` and the per-event behavioural pins
 * (`testEachInvocationOnFreshRequestMintsADistinctUuidV7`,
 * `testEachInvocationOnFreshRequestEmitsADistinctMintedHeaderWhenAttributeMissing`).
 *
 * Priorities pinned at `self::PRIORITY` (1024) and `self::RESPONSE_PRIORITY` (-1024) via class
 * constants + reflection regression tests (`testListenerPriorityIsPinnedAtClassConstantValue`,
 * `testResponseListenerPriorityIsPinnedAtClassConstantValue`).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class CorrelationIdListener
{
    public const int PRIORITY = 1024;

    public const int RESPONSE_PRIORITY = -1024;

    public const string ATTRIBUTE_KEY = '_correlation_id';

    public const string HEADER_NAME = 'X-Correlation-Id';

    private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $inboundAll = $request->headers->all(self::HEADER_NAME);
        $inbound = (1 === \count($inboundAll)) ? $inboundAll[0] : null;

        $resolved = (\is_string($inbound) && 1 === \preg_match(self::UUIDV7_PATTERN, $inbound))
            ? $inbound
            : Uuid::v7()->toRfc4122();

        $request->attributes->set(self::ATTRIBUTE_KEY, $resolved);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $stored = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);

        $resolved = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
            ? $stored
            : Uuid::v7()->toRfc4122();

        $event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
    }
}
