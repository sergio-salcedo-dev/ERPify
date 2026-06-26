<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Application\Resource;

/**
 * Wire contract of one audit timeline row (`GET /audit/timeline`). Plain, flat, scalar-only: the
 * serialized object is this DTO, never an entity. Every field is a string the mapper pre-formats,
 * except `actorErased` — a boolean carrying the materialised GDPR-erasure state, surfaced in the slim
 * row so an erased subject reads as such without lifting the more sensitive `ip`/`user_agent`.
 * `occurredOn` is an ISO-8601 string at microsecond precision (forensic instant);
 * `actorId`/`resourceType`/`resourceId` are null when the record carries no actor/resource.
 *
 * The detail-only fields (`ip`, `userAgent`, `metadata`) are intentionally absent from the timeline
 * view; they belong to the investigation detail view.
 */
final readonly class AuditTimelineResource
{
    public function __construct(
        public string $id,
        public string $occurredOn,
        public string $level,
        public string $action,
        public string $actorType,
        public ?string $actorId,
        public string $correlationId,
        public ?string $resourceType,
        public ?string $resourceId,
        public bool $actorErased,
    ) {
    }
}
