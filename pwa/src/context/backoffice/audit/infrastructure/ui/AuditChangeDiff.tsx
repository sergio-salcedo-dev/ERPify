"use client";

import { useState } from "react";
import { ArrowRight, CircleDashed, Lock, Minus, Plus, type LucideIcon } from "lucide-react";
import { cn } from "@/components/cn";
import { CopyButton, TruncatedText } from "@/components/erpify";
import {
  ChangeKind,
  changeKind,
  isAuditSealedValue,
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
  [ChangeKind.Empty]: "Not set",
};

const KIND_TEXT_TONE: Readonly<Record<ChangeKind, string>> = {
  [ChangeKind.Added]: "text-success-strong",
  [ChangeKind.Removed]: "text-danger-strong",
  [ChangeKind.Changed]: "text-muted-foreground",
  [ChangeKind.Empty]: "text-muted-foreground",
};

const KIND_ICON: Readonly<Record<ChangeKind, LucideIcon>> = {
  [ChangeKind.Added]: Plus,
  [ChangeKind.Removed]: Minus,
  [ChangeKind.Changed]: ArrowRight,
  [ChangeKind.Empty]: CircleDashed,
};

const VALUE_TYPE_LABEL: Readonly<Record<string, string>> = {
  string: "text",
  number: "number",
  boolean: "boolean",
};

/**
 * Field-by-field write diff for a `change` audit row, fed the decoded `metadata.changes`. Four states
 * carried on a **non-colour** channel (WCAG 1.4.1): a text marker + glyph for added / removed /
 * changed / not-set, with colour only reinforcing. Every captured field is rendered, empty ones
 * included — a field that was present and unpopulated is evidence, and this view is the only place
 * it surfaces. Large diffs collapse so the drawer layout holds; empty fields sort last so collapsing
 * never hides a populated one behind them.
 *
 * It deliberately makes **no claim about the write's direction**. Naming a diff «Initial state» or
 * «Final state before deletion» requires knowing the operation, and the operation is not carried by
 * the event: inferring it from the rows asserts CREATE over any update that only fills previously
 * empty fields. Until the trail transports the operation, this view shows the evidence and names
 * nothing.
 *
 * SECURITY (load-bearing): every value is untrusted input (an editable bank name) rendered as a React
 * text child (auto-escaped). NEVER `dangerouslySetInnerHTML`/`innerHTML`; no value feeds `href`/`src`.
 * Mirror of `MetadataBlock`'s escaping stance.
 */
export function AuditChangeDiff({ changes, className, testId }: Readonly<AuditChangeDiffProps>) {
  const rows: FieldRow[] = orderedRows(changes);
  const [expanded, setExpanded] = useState(false);

  if (rows.length === 0) {
    return (
      <p className={cn("text-muted-foreground text-xs", className)} data-testid={testId}>
        No changes recorded
      </p>
    );
  }

  const collapsible = rows.length > COLLAPSE_THRESHOLD;
  const visibleRows = collapsible && !expanded ? rows.slice(0, COLLAPSE_VISIBLE) : rows;
  const hiddenCount = rows.length - visibleRows.length;

  return (
    <div className={cn("audit-change-diff flex flex-col gap-2", className)} data-testid={testId}>
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
  const typeHint = typeHintFor(change);
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
          {typeHint === null ? null : (
            <>
              <span aria-hidden="true">·</span>
              <span>{typeHint}</span>
            </>
          )}
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

/**
 * Renders the before/after for a field: a single value for add/remove, `old → new` for a change, and
 * a single sentinel for a field that never held one — never `old → new` over two empties, which
 * would read as a modification that did not happen. Exhaustive on {@link ChangeKind} so a fifth
 * state is a compile error here rather than a silent fall-through to the change arm.
 */
function ChangeValue({ change, kind }: Readonly<{ change: AuditFieldChange; kind: ChangeKind }>) {
  switch (kind) {
    case ChangeKind.Added:
      return <ScalarValue value={change.new} />;
    case ChangeKind.Removed:
    case ChangeKind.Empty:
      return <ScalarValue value={change.old} />;
    case ChangeKind.Changed:
      return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
          <ScalarValue value={change.old} />
          <ArrowRight className="text-muted-foreground size-3.5 flex-none" aria-hidden="true" />
          <ScalarValue value={change.new} />
        </span>
      );
  }
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

/**
 * Every captured field, with the empty ones last. The wire order is Doctrine's field-metadata order,
 * not a business order, so nothing forensic is lost by re-sorting — while keeping empty fields ahead
 * of populated ones would let them fill the collapsed window and hide real values behind a toggle.
 * The sort is stable, so fields keep their relative order inside each group.
 */
function orderedRows(changes: AuditChanges): FieldRow[] {
  const rows = Object.entries(changes).map(([field, change]) => ({
    field,
    change,
    kind: changeKind(change),
  }));
  const isEmpty = (row: FieldRow) => row.kind === ChangeKind.Empty;
  return [...rows.filter((row) => !isEmpty(row)), ...rows.filter(isEmpty)];
}

/**
 * The field-type hint shown next to the label, derived from whichever side carries a value. A field
 * that never held one has no derivable type — the diff carries no schema — so it reports `null` and
 * the caller omits the segment rather than printing a placeholder that asserts nothing.
 */
function typeHintFor(change: AuditFieldChange): string | null {
  const present = change.new ?? change.old;
  if (present === null) return null;
  if (isAuditSealedValue(present)) return "encrypted";
  return VALUE_TYPE_LABEL[typeof present] ?? typeof present;
}

function idOf(testId: string | undefined, suffix: string): string | undefined {
  return testId ? `${testId}__${suffix}` : undefined;
}
