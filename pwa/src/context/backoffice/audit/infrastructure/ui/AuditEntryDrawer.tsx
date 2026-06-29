"use client";

import type { ReactNode } from "react";
import { Footprints, GitBranch, Package } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton, CorrelationIdChip, RecordSheet } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import { humanizeAuditAction } from "@/context/backoffice/audit/application/humanizeAuditAction";
import { AuditLevelBadge } from "./AuditLevelBadge";
import { ActorChip } from "./ActorChip";
import { RedactedValue } from "./RedactedValue";
import { MetadataBlock } from "./MetadataBlock";

/** The `ip`/`user_agent` redaction sentinel the backend writes after a GDPR erasure. */
const REDACTED_SENTINEL = "[REDACTED]";

/**
 * The detail-only fields, from the 4.2a read model. Optional: until that endpoint lands (deferred
 * until auth), the drawer renders these sections as **specified-but-dormant** — the structure is
 * present, the values arrive with the detail view. `ip`/`userAgent` are `[REDACTED]` after erasure,
 * a real value (forensic evidence), or null («—»); `metadata` carries no sensitive payload (D4).
 */
export interface AuditEntryDetail {
  ip: string | null;
  userAgent: string | null;
  metadata: unknown;
}

interface AuditEntryDrawerProps {
  entry: AuditEntry | null;
  open: boolean;
  onClose: () => void;
  detail?: AuditEntryDetail;
  onFollowActor?: (entry: AuditEntry) => void;
  onFollowCorrelation?: (entry: AuditEntry) => void;
  onFollowResource?: (entry: AuditEntry) => void;
}

/**
 * Read-only investigation detail in a `<RecordSheet>` drawer (never a separate page — the timeline
 * stays behind it). The only actions are **query pivots** (follow actor / correlation / resource,
 * which re-filter the same `audit_log`) and **copy**; there is no write path (D5). This drawer is the
 * complete keyboard equivalent of the row — every pivot and copy is reachable here — so no capability
 * is pointer-only.
 */
export function AuditEntryDrawer({
  entry,
  open,
  onClose,
  detail,
  onFollowActor,
  onFollowCorrelation,
  onFollowResource,
}: Readonly<AuditEntryDrawerProps>) {
  return (
    <RecordSheet
      open={open && entry !== null}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
      title={entry ? humanizeAuditAction(entry.action) : ""}
      subtitle={entry?.action}
      testId="audit-entry-drawer"
    >
      {entry ? (
        <AuditEntryDrawerBody
          entry={entry}
          detail={detail}
          onFollowActor={onFollowActor}
          onFollowCorrelation={onFollowCorrelation}
          onFollowResource={onFollowResource}
        />
      ) : null}
    </RecordSheet>
  );
}

function AuditEntryDrawerBody({
  entry,
  detail,
  onFollowActor,
  onFollowCorrelation,
  onFollowResource,
}: Readonly<Omit<AuditEntryDrawerProps, "open" | "onClose"> & { entry: AuditEntry }>) {
  const followActor = onFollowActor && entry.actorId !== null && !entry.actorErased;
  const followResource =
    onFollowResource && (entry.resourceType !== null || entry.resourceId !== null);

  return (
    <div className="audit-entry-drawer flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <AuditLevelBadge level={entry.level} />
        {followActor ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onFollowActor?.(entry)}
            data-testid="audit-entry-drawer__follow-actor"
            title="Seguir a este actor"
          >
            <Footprints className="size-3.5" aria-hidden="true" />
            Seguir a este actor
          </Button>
        ) : null}
        {onFollowCorrelation ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onFollowCorrelation(entry)}
            data-testid="audit-entry-drawer__follow-correlation"
            title="Ver esta correlación"
          >
            <GitBranch className="size-3.5" aria-hidden="true" />
            Ver esta correlación
          </Button>
        ) : null}
        {followResource ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onFollowResource?.(entry)}
            data-testid="audit-entry-drawer__follow-resource"
            title="Ver actividad de este recurso"
          >
            <Package className="size-3.5" aria-hidden="true" />
            Ver actividad de este recurso
          </Button>
        ) : null}
      </div>

      <Section title="Qué">
        <Field label="Acción">
          <span className="flex flex-col gap-0.5">
            <span>{humanizeAuditAction(entry.action)}</span>
            <span className="inline-flex items-center gap-1">
              <code className="text-text-subtle font-mono text-xs">{entry.action}</code>
            </span>
          </span>
        </Field>
        <Field label="Cuándo">
          <span className="flex flex-col gap-0.5">
            <span>
              {dateTimeProvider.formatIsoToLocalDateTime(entry.occurredOn)}
              <span className="text-text-subtle">
                {" "}
                · {dateTimeProvider.formatIsoToRelative(entry.occurredOn)}
              </span>
            </span>
            <span className="inline-flex items-center gap-1">
              <code className="text-text-subtle font-mono text-xs">{entry.occurredOn}</code>
              <CopyButton
                value={entry.occurredOn}
                iconOnly
                size="sm"
                variant="ghost"
                label="Copiar instante ISO"
                title="Copiar instante ISO"
              />
            </span>
          </span>
        </Field>
        <Field label="Id de entrada">
          <span className="inline-flex items-center gap-1">
            <code className="font-mono text-xs">{entry.id}</code>
            <CopyButton
              value={entry.id}
              iconOnly
              size="sm"
              variant="ghost"
              label="Copiar id de entrada"
              title="Copiar id de entrada"
              testId="audit-entry-drawer__copy-id"
            />
          </span>
        </Field>
      </Section>

      <Section title="Quién">
        <Field label="Actor">
          <ActorChip
            actorType={entry.actorType}
            actorId={entry.actorId}
            actorErased={entry.actorErased}
          />
        </Field>
        <Field label="IP">{detail ? <TaintedValue value={detail.ip} /> : <DormantDetail />}</Field>
        <Field label="User agent">
          {detail ? <TaintedValue value={detail.userAgent} /> : <DormantDetail />}
        </Field>
      </Section>

      <Section title="Sobre qué">
        <Field label="Recurso">
          <ResourceValue entry={entry} />
        </Field>
      </Section>

      <Section title="Correlación">
        <Field label="Correlación">
          <CorrelationIdChip id={entry.correlationId} />
        </Field>
      </Section>

      <Section title="Metadata">
        {detail ? <MetadataBlock value={detail.metadata} /> : <DormantDetail />}
      </Section>
    </div>
  );
}

/** `resourceType · resourceId` (id copyable, never a deep-link), or «—» when the entry references neither. */
function ResourceValue({ entry }: Readonly<{ entry: AuditEntry }>) {
  if (!entry.resourceType && !entry.resourceId) {
    return <span className="text-text-subtle">—</span>;
  }
  return (
    <span className="inline-flex items-center gap-1">
      {entry.resourceType ? <span>{entry.resourceType}</span> : null}
      {entry.resourceId ? (
        <>
          {entry.resourceType ? <span className="text-text-subtle">·</span> : null}
          <code className="font-mono text-xs">{entry.resourceId}</code>
          <CopyButton
            value={entry.resourceId}
            iconOnly
            size="sm"
            variant="ghost"
            label="Copiar id de recurso"
            title="Copiar id de recurso"
          />
        </>
      ) : null}
    </span>
  );
}

/** Tainted `ip`/`user_agent`: the redaction sentinel, a real value (escaped, copyable), or a real null. */
function TaintedValue({ value }: Readonly<{ value: string | null }>) {
  if (value === REDACTED_SENTINEL) return <RedactedValue />;
  if (value === null || value === "") return <span className="text-text-subtle">—</span>;
  return (
    <span className="inline-flex items-center gap-1">
      <code className="font-mono text-xs break-all">{value}</code>
      <CopyButton
        value={value}
        iconOnly
        size="sm"
        variant="ghost"
        label="Copiar valor"
        title="Copiar valor"
      />
    </span>
  );
}

/** Placeholder for a detail-only field while the 4.2a detail endpoint is deferred (until auth). */
function DormantDetail() {
  return (
    <span className="text-text-subtle text-xs italic" data-testid="audit-entry-drawer__dormant">
      Disponible en la vista de detalle
    </span>
  );
}

function Section({ title, children }: Readonly<{ title: string; children: ReactNode }>) {
  return (
    <section className="audit-entry-drawer__section flex flex-col gap-2">
      <h3 className="text-text-subtle text-xs font-semibold tracking-wide uppercase">{title}</h3>
      <dl className="flex flex-col gap-2">{children}</dl>
    </section>
  );
}

function Field({ label, children }: Readonly<{ label: string; children: ReactNode }>) {
  return (
    <div className="grid grid-cols-[8rem_1fr] items-start gap-2">
      <dt className="text-muted-foreground text-xs">{label}</dt>
      <dd className="text-foreground text-sm break-words">{children}</dd>
    </div>
  );
}
