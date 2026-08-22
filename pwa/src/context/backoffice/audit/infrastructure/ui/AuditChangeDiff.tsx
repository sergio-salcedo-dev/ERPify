"use client";

import { useState } from "react";
import { ArrowRight, Lock, Minus, Plus, type LucideIcon } from "lucide-react";
import { cn } from "@/components/cn";
import { CopyButton, TruncatedText } from "@/components/erpify";
import {
  ChangeKind,
  changeKind,
  isAuditSealedValue,
  isNoOpChange,
  type AuditChanges,
  type AuditFieldChange,
  type AuditFieldValue,
} from "@/context/backoffice/audit/domain/AuditChange";
import { humanizeAuditField } from "@/context/backoffice/audit/application/humanizeAuditField";

/** Past this many fields the diff collapses to the first {@link COLLAPSE_VISIBLE} with a reveal toggle. */
const COLLAPSE_THRESHOLD = 8;
const COLLAPSE_VISIBLE = 6;
/** Past this many characters a value wraps in {@link TruncatedText} and offers a copy affordance. */
const LONG_VALUE_LENGTH = 40;

interface AuditChangeDiffProps {
  changes: AuditChanges;
  className?: string;
  testId?: string;
}

interface FieldRow {
  field: string;
  change: AuditFieldChange;
  kind: ChangeKind;
}

const KIND_MARKER: Readonly<Record<ChangeKind, string>> = {
  [ChangeKind.Added]: "Added",
  [ChangeKind.Removed]: "Removed",
  [ChangeKind.Changed]: "Changed",
};

const KIND_TEXT_TONE: Readonly<Record<ChangeKind, string>> = {
  [ChangeKind.Added]: "text-success-strong",
  [ChangeKind.Removed]: "text-danger-strong",
  [ChangeKind.Changed]: "text-muted-foreground",
};

const KIND_ICON: Readonly<Record<ChangeKind, LucideIcon>> = {
  [ChangeKind.Added]: Plus,
  [ChangeKind.Removed]: Minus,
  [ChangeKind.Changed]: ArrowRight,
};

const VALUE_TYPE_LABEL: Readonly<Record<string, string>> = {
  string: "text",
  number: "number",
  boolean: "boolean",
};

/**
 * Field-by-field write diff for a `change` audit row, fed the decoded `metadata.changes`. Three states
 * carried on a **non-colour** channel (WCAG 1.4.1): a text marker + glyph for added / removed /
 * changed, with colour only reinforcing. A CREATE (all-added) or DELETE (all-removed) reads as a full
 * snapshot under a naming header. Large diffs collapse so the drawer layout holds.
 *
 * SECURITY (load-bearing): every value is untrusted input (an editable bank name) rendered as a React
 * text child (auto-escaped). NEVER `dangerouslySetInnerHTML`/`innerHTML`; no value feeds `href`/`src`.
 * Mirror of `MetadataBlock`'s escaping stance.
 */
export function AuditChangeDiff({ changes, className, testId }: Readonly<AuditChangeDiffProps>) {
  const rows: FieldRow[] = Object.entries(changes)
    .filter(([, change]) => !isNoOpChange(change))
    .map(([field, change]) => ({
      field,
      change,
      kind: changeKind(change),
    }));
  const [expanded, setExpanded] = useState(false);

  if (rows.length === 0) {
    // Every captured field was empty (null on both sides): still a real snapshot, but its direction
    // (create vs delete) is not derivable from the diff alone — so it reads as a faithful empty record
    // rather than mislabelling a real write as "no changes". A genuinely empty map keeps the plain copy.
    const hasCapturedFields = Object.keys(changes).length > 0;
    return (
      <p className={cn("text-muted-foreground text-xs", className)} data-testid={testId}>
        {hasCapturedFields ? "Record with no populated fields" : "No changes recorded"}
      </p>
    );
  }

  const snapshotHeader = snapshotHeaderFor(rows);
  const collapsible = rows.length > COLLAPSE_THRESHOLD;
  const visibleRows = collapsible && !expanded ? rows.slice(0, COLLAPSE_VISIBLE) : rows;
  const hiddenCount = rows.length - visibleRows.length;

  return (
    <div className={cn("audit-change-diff flex flex-col gap-2", className)} data-testid={testId}>
      {snapshotHeader ? (
        <p
          className="text-muted-foreground text-xs font-medium"
          data-testid={idOf(testId, "snapshot")}
        >
          {snapshotHeader}
        </p>
      ) : null}

      <dl className="flex flex-col gap-2.5">
        {visibleRows.map((row) => (
          <DiffField key={row.field} row={row} testId={idOf(testId, `field-${row.field}`)} />
        ))}
      </dl>

      {collapsible ? (
        <button
          type="button"
          onClick={() => setExpanded((prev) => !prev)}
          className="text-foreground hover:text-accent-hover self-start text-xs font-medium underline-offset-2 hover:underline"
          data-testid={idOf(testId, "toggle")}
          title={expanded ? "Hide fields" : `Show ${hiddenCount} more fields`}
        >
          {expanded ? "Show less" : `Show ${hiddenCount} more fields`}
        </button>
      ) : null}
    </div>
  );
}

function DiffField({ row, testId }: Readonly<{ row: FieldRow; testId?: string }>) {
  const { field, change, kind } = row;
  return (
    <div
      className="grid grid-cols-[10rem_1fr] items-start gap-2"
      data-testid={testId}
      data-kind={kind}
    >
      <dt className="flex flex-col gap-0.5">
        <span className="text-foreground text-sm font-medium break-words">
          {humanizeAuditField(field)}
        </span>
        <span className="text-muted-foreground inline-flex flex-wrap items-center gap-1 text-2xs">
          <code className="font-mono break-all">{field}</code>
          <span aria-hidden="true">·</span>
          <span>{typeHintFor(change)}</span>
        </span>
        <KindMarker kind={kind} />
      </dt>
      <dd className="text-foreground text-sm break-words">
        <ChangeValue change={change} kind={kind} />
      </dd>
    </div>
  );
}

function KindMarker({ kind }: Readonly<{ kind: ChangeKind }>) {
  const Icon = KIND_ICON[kind];
  return (
    <span
      className={cn("inline-flex items-center gap-1 text-2xs font-medium", KIND_TEXT_TONE[kind])}
    >
      <Icon className="size-3" aria-hidden="true" />
      {KIND_MARKER[kind]}
    </span>
  );
}

/** Renders the before/after for a field: a single value for add/remove, `old → new` for a change. */
function ChangeValue({ change, kind }: Readonly<{ change: AuditFieldChange; kind: ChangeKind }>) {
  if (kind === ChangeKind.Added) return <ScalarValue value={change.new} />;
  if (kind === ChangeKind.Removed) return <ScalarValue value={change.old} />;
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <ScalarValue value={change.old} />
      <ArrowRight className="text-muted-foreground size-3.5 flex-none" aria-hidden="true" />
      <ScalarValue value={change.new} />
    </span>
  );
}

/**
 * One scalar, rendered as escaped React text. `null` reads as a sentinel «— (empty)»; the empty
 * string reads as «(empty string)» — the two are never collapsed. A long value wraps in
 * `TruncatedText` (full string stays in the DOM) and offers a copy affordance.
 */
function ScalarValue({ value }: Readonly<{ value: AuditFieldValue }>) {
  if (isAuditSealedValue(value)) {
    return (
      <span className="text-muted-foreground inline-flex items-center gap-1 italic">
        <Lock className="size-3" aria-hidden="true" />
        encrypted (not available)
      </span>
    );
  }
  if (value === null) {
    return <span className="text-muted-foreground italic">— (empty)</span>;
  }
  if (value === "") {
    return <span className="text-muted-foreground italic">(empty string)</span>;
  }
  const text = String(value);
  if (text.length > LONG_VALUE_LENGTH) {
    return (
      <span className="inline-flex max-w-full items-start gap-1">
        <TruncatedText
          value={text}
          lines={2}
          openOnRowFocus={false}
          className="font-mono text-xs"
        />
        <CopyButton
          value={text}
          iconOnly
          size="sm"
          variant="ghost"
          label="Copy value"
          title="Copy value"
        />
      </span>
    );
  }
  return <span className="break-words">{text}</span>;
}

/** «Initial state» when the row is a pure CREATE (all-added), «… before deletion» for a DELETE. */
function snapshotHeaderFor(rows: ReadonlyArray<FieldRow>): string | null {
  if (rows.every((row) => row.kind === ChangeKind.Added)) return "Initial state";
  if (rows.every((row) => row.kind === ChangeKind.Removed)) {
    return "Final state before deletion";
  }
  return null;
}

/** The field-type hint shown next to the label, derived from whichever side carries a value. */
function typeHintFor(change: AuditFieldChange): string {
  const present = change.new ?? change.old;
  if (present === null) return "—";
  if (isAuditSealedValue(present)) return "encrypted";
  return VALUE_TYPE_LABEL[typeof present] ?? typeof present;
}

function idOf(testId: string | undefined, suffix: string): string | undefined {
  return testId ? `${testId}__${suffix}` : undefined;
}
