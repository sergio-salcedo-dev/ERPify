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
 *
 * `metadata.changes` is sealed the same way, and for the same reason one level down. A `change` row can
 * legitimately hold an EMPTY diff — a write whose every field the diff builder discards (a to-many
 * collection alone, say) still happened, so the row is kept as evidence rather than skipped — and the
 * read side decodes it with `json_decode(…, true)`, which collapses a stored `{}` and `[]` into the same
 * PHP `[]`. Sealing it at write time therefore cannot hold; this mapper is the one place that controls the
 * wire, so it is also the one place that covers rows already stored. Only `changes` is sealed, and only
 * when empty or keyed: forcing every nested array into an object would turn a legitimate list inside a
 * value into `{"0": …}`, and a list-shaped `changes` is drift the client must still see as a list.
 *
 * **A `changes` that is not a map keeps its SHAPE on the wire and loses its CONTENT.** A scalar becomes
 * {@see self::WITHHELD}, a non-empty list becomes a list of as many of them, and `null` stays `null`. Sealing
 * only ever reached a map: `PiiDiffSealer` encrypts the classified fields of a map, so a value that is not
 * keyed by field name reached storage in clear whatever it holds, and nothing on the erasure path reaches it
 * afterwards — both anonymisers rewrite columns and never `metadata`, and crypto-shredding destroys a key,
 * which only matters to a value that was encrypted under it. Serving it verbatim would therefore hand out,
 * for as long as the row is retained, whatever personal data a corrupt row happens to carry, past the erasure
 * of the person it names.
 * The shape is what the corruption signal needs and it survives: neither wrapped into a map nor deleted, so
 * the client still degrades a scalar to an unreadable diff and still refuses a list (why the two differ is
 * recorded in the audit ADR, D4). What is spent is the raw value on the wire; whoever investigates a corrupt
 * row reads it from the table, under the access that table already demands.
 *
 * The test is the decoded shape: `json_decode()` reads an object keyed `"0"`, `"1"`, … as a list, so such a map
 * is withheld as one — a shape the writer never produces.
 */
final readonly class AuditEventDetailResourceMapper
{
    /**
     * What a `changes` that is not a map serves in place of each value it held. A string, so a scalar stays a
     * scalar and a list stays a list; and not the erasure sentinel, because nothing was erased — the value
     * still sits in the row, withheld by the read side.
     */
    public const string WITHHELD = '[unsealed value withheld]';

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
            new ArrayObject($this->withChangesAsMap($detail->metadata)),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function withChangesAsMap(array $metadata): array
    {
        $changes = $metadata['changes'] ?? null;

        // A non-empty LIST is not a diff, and wrapping it would serve it as `{"0": …}` — a map the client
        // accepts and renders as a field named "0", hiding the drift its guard would otherwise refuse.
        if (\is_array($changes) && ([] === $changes || !\array_is_list($changes))) {
            $metadata['changes'] = new ArrayObject($changes);

            return $metadata;
        }

        if (\is_array($changes)) {
            $metadata['changes'] = \array_fill(0, \count($changes), self::WITHHELD);
        } elseif (null !== $changes) {
            $metadata['changes'] = self::WITHHELD;
        }

        return $metadata;
    }
}
