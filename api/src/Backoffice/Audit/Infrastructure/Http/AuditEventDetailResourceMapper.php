<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Http;

use ArrayObject;
use Erpify\Backoffice\Audit\Application\Resource\AuditEventDetailResource;
use Erpify\Backoffice\Audit\Domain\AuditEventDetail;

/**
 * Maps an {@see AuditEventDetail} read model to the {@see AuditEventDetailResource} wire DTO — the
 * single place that knows the detail shape, so no read model reaches the serializer. `occurredOn` is
 * rendered at microsecond precision (the forensic instant), matching the timeline mapper; `metadata` is
 * wrapped in an {@see ArrayObject} — the decoded diff is unchanged, but the wrapper is what makes an
 * entry carrying none serialize as `{}` rather than `[]`, which is the shape the client's guard admits.
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
            $detail->resourceErased,
            new ArrayObject($detail->metadata),
        );
    }
}
