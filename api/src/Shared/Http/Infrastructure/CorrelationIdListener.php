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
 * response (kernel.response, priority `RESPONSE_PRIORITY = -1024`). The value flows one way:
 * minted here → `_correlation_id` request attribute → `X-Correlation-Id` response header.
 *
 * **The server owns this value; an inbound `X-Correlation-Id` is ignored, not validated.** The
 * id is the column `audit_log` rows are grouped by, so whoever can choose it can choose how the
 * forensic trail reads: send one value on N unrelated requests and their rows collapse into one
 * apparent journey; send another actor's value and rows join under an identity that is not
 * theirs. No check on a single request can separate a reused id from a fresh one — a shape test
 * proves the value looks like a UUIDv7, never that its bearer minted it — so the only property
 * that holds is the one taken here: nothing outside the process can name it. The header is
 * dropped silently rather than logged, because logging it would move the same caller-chosen
 * string into a sink with no TTL and no erasure owner.
 *
 * What that forecloses is inbound trace propagation: a caller cannot ask for its request to
 * join an existing correlation. Nothing does — the PWA only reads the value off the response —
 * and when a second service or a gateway needs distributed tracing, it needs an identifier of
 * its own rather than authority over this one.
 *
 * On the response side, the request attribute is **re-validated** against a strict lowercase
 * UUIDv7 pattern (RFC 9562 §6.10) before being written to the header — defense-in-depth against
 * any listener that may have tampered with `_correlation_id` between kernel.request and
 * kernel.response. The pattern uses `\A…\z` anchors (not `^…$`) so PHP's default
 * `$`-before-final-`\n` semantics cannot leak a trailing newline through. The header write
 * overwrites any pre-existing value, so a caller's own header never survives onto the response.
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

        $event->getRequest()->attributes->set(self::ATTRIBUTE_KEY, Uuid::v7()->toRfc4122());
    }

    /**
     * Whether `$value` is a canonical lowercase UUIDv7 (RFC 9562 §6.10). The single definition of the
     * correlation-id format, so every consumer that must validate it shares this anchor instead of
     * carrying a second regex that could drift from the one used to mint and propagate the value.
     */
    public static function isCanonical(string $value): bool
    {
        return 1 === \preg_match(self::UUIDV7_PATTERN, $value);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $stored = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);

        $resolved = (\is_string($stored) && self::isCanonical($stored))
            ? $stored
            : Uuid::v7()->toRfc4122();

        $event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
    }
}
