<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http;

use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves which resource a read touched, so a generic access-log row records *which* record was seen
 * rather than only that some list was viewed. The identity is owned by the route, not by a catalogue here:
 * the route declares its resource type on its `defaults` (surfaced as the {@see self::RESOURCE_TYPE_ATTRIBUTE}
 * request attribute, an underscore-prefixed framework-internal default like `_audit_canonical`), and the id
 * is its `{id}` path parameter. This keeps {@see \Erpify\Shared\Audit\Domain\AuditPolicy} free of any
 * per-module route map, mirroring how the canonical-audit declaration already lives on the route.
 *
 * A read with no declared type, a collection route with no single `{id}`, or a `{id}` that is not a UUID
 * has no single audit-keyed resource, so the extraction yields null and the row stays resource-less. The
 * non-UUID case keeps this terminate-time access log best-effort: {@see AuditResource} rejects a non-UUID id
 * (the `audit_log.resource_id` column is a `uuid`), which the write-capture path *wants* mid-flush, but a
 * read that already responded 2xx must never throw on `kernel.terminate`.
 */
final readonly class RequestAuditResourceExtractor
{
    private const string RESOURCE_TYPE_ATTRIBUTE = '_audit_resource_type';

    private const string RESOURCE_ID_ATTRIBUTE = 'id';

    public function extract(Request $request): ?AuditResource
    {
        $type = $request->attributes->get(self::RESOURCE_TYPE_ATTRIBUTE);
        $id = $request->attributes->get(self::RESOURCE_ID_ATTRIBUTE);

        if (!\is_string($type) || '' === $type || !\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        return AuditResource::of($type, $id);
    }
}
