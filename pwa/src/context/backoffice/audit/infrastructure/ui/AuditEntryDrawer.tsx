"use client";

import type { ReactNode } from "react";
import { Footprints, GitBranch, Package } from "lucide-react";
import { Button } from "@/components/ui/button";
import { CopyButton, CorrelationIdChip, RecordSheet } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { AuditLevel, type AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import type { AuditEventDetail } from "@/context/backoffice/audit/domain/AuditChange";
import { humanizeAuditAction } from "@/context/backoffice/audit/application/humanizeAuditAction";
import { AuditLevelBadge } from "./AuditLevelBadge";
import { ActorChip } from "./ActorChip";
import { ErasedResource } from "./ErasedResource";
import { AuditChangeDiff } from "./AuditChangeDiff";
import { MetadataBlock } from "./MetadataBlock";

interface AuditEntryDrawerProps {
  entry: AuditEntry | null;
  open: boolean;
  onClose: () => void;
  /**
   * The fetched event detail (`/audit/events/{id}`): carries the decoded `metadata` (with the diff
   * for a `change` row). Optional — until it loads, the diff/metadata sections render dormant.
   * `ip`/`userAgent` are NOT part of the E1 diff-only payload, so those rows stay dormant by design.
   */
  detail?: AuditEventDetail;
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
  // Symmetrical to the actor guard above, and for the same reason: once an axis is erased its id is an
  // anonymisation pseudonym, so following it filters the trail by an identity that denotes nobody while
  // presenting it as a live reference. The compliance row of an identity erasure is exactly this case.
  const followResource =
    onFollowResource &&
    (entry.resourceType !== null || entry.resourceId !== null) &&
    !entry.resourceErased;

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
            title="Follow this actor"
          >
            <Footprints className="size-3.5" aria-hidden="true" />
            Follow this actor
          </Button>
        ) : null}
        {onFollowCorrelation ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onFollowCorrelation(entry)}
            data-testid="audit-entry-drawer__follow-correlation"
            title="Follow this correlation"
          >
            <GitBranch className="size-3.5" aria-hidden="true" />
            Follow this correlation
          </Button>
        ) : null}
        {followResource ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onFollowResource?.(entry)}
            data-testid="audit-entry-drawer__follow-resource"
            title="View this resource's activity"
          >
            <Package className="size-3.5" aria-hidden="true" />
            View this resource&apos;s activity
          </Button>
        ) : null}
      </div>

      <Section title="What">
        <Field label="Action">
          <span className="flex flex-col gap-0.5">
            <span>{humanizeAuditAction(entry.action)}</span>
            <span className="inline-flex items-center gap-1">
              <code className="text-text-subtle font-mono text-xs">{entry.action}</code>
            </span>
          </span>
        </Field>
        <Field label="When">
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
                label="Copy ISO instant"
                title="Copy ISO instant"
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
              label="Copy entry id"
              title="Copy entry id"
              testId="audit-entry-drawer__copy-id"
            />
          </span>
        </Field>
      </Section>

      <Section title="Who">
        <Field label="Actor">
          <ActorChip
            actorType={entry.actorType}
            actorId={entry.actorId}
            actorErased={entry.actorErased}
          />
        </Field>
        <Field label="IP">
          <DormantDetail />
        </Field>
        <Field label="User agent">
          <DormantDetail />
        </Field>
      </Section>

      <Section title="On what">
        <Field label="Resource">
          <ResourceValue entry={entry} />
        </Field>
      </Section>

      <Section title="Correlation">
        <Field label="Correlation">
          <CorrelationIdChip id={entry.correlationId} />
        </Field>
      </Section>

      {entry.level === AuditLevel.Change ? (
        <Section title="Changes">
          {detail ? (
            <AuditChangeDiff
              changes={detail.metadata.changes ?? {}}
              operation={detail.metadata.operation}
              testId="audit-entry-drawer__diff"
            />
          ) : (
            <DormantDetail />
          )}
        </Section>
      ) : null}

      <Section title="Metadata">
        {detail ? <MetadataBlock value={nonDiffMetadata(detail.metadata)} /> : <DormantDetail />}
      </Section>
    </div>
  );
}

/**
 * The metadata minus `changes` and `operation` — the Changes section already renders the diff (and its
 * snapshot header, sourced from `operation`) in full.
 */
function nonDiffMetadata(metadata: Record<string, unknown>): Record<string, unknown> {
  const rest: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(metadata)) {
    if (key !== "changes" && key !== "operation") rest[key] = value;
  }
  return rest;
}

/**
 * `resourceType · resourceId` (id copyable, never a deep-link), the anonymized state when the axis has
 * been erased, or «—» when the entry references neither.
 */
function ResourceValue({ entry }: Readonly<{ entry: AuditEntry }>) {
  if (!entry.resourceType && !entry.resourceId) {
    return <span className="text-text-subtle">—</span>;
  }
  if (entry.resourceErased) return <ErasedResource resourceType={entry.resourceType} />;
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
            label="Copy resource id"
            title="Copy resource id"
          />
        </>
      ) : null}
    </span>
  );
}

/**
 * Placeholder for the still-dormant `ip`/`user_agent` fields: the E1 detail payload is diff-only, so
 * those (PII) values arrive with a later auth-gated read model, not here.
 */
function DormantDetail() {
  return (
    <span className="text-text-subtle text-xs italic" data-testid="audit-entry-drawer__dormant">
      Available in the detail view
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
