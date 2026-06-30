<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Http;

use Erpify\Backoffice\Audit\Application\Resource\AuditEventDetailResource;
use Erpify\Backoffice\Audit\Domain\AuditEventDetail;

/**
 * Maps an {@see AuditEventDetail} read model to the {@see AuditEventDetailResource} wire DTO — the
 * single place that knows the detail shape, so no read model reaches the serializer. `occurredOn` is
 * rendered at microsecond precision (the forensic instant), matching the timeline mapper; `metadata`
 * passes through unchanged — it is already the decoded diff and the serializer emits it verbatim.
 */
final readonly class AuditEventDetailResourceMapper
{
    /** ISO-8601 with the microsecond fraction `occurred_on` (TIMESTAMPTZ(6)) carries. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function toResource(AuditEventDetail $detail): AuditEventDetailResource
    {
        return new AuditEventDetailResource(
            $detail->id,
            $detail->occurredOn->format(self::TIMESTAMP_FORMAT),
            $detail->level,
            $detail->action,
            $detail->actorType,
            $detail->actorId,
            $detail->correlationId,
            $detail->resourceType,
            $detail->resourceId,
            $detail->actorErased,
            $detail->metadata,
        );
    }
}
