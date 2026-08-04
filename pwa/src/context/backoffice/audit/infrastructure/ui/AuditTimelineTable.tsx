"use client";

import { useCallback, useId, useMemo, useRef, useState } from "react";
import type { KeyboardEvent, MouseEvent, ReactNode } from "react";
import { ArrowDown, ArrowUp } from "lucide-react";
import { cn } from "@/components/cn";
import { CopyButton, CorrelationIdChip, TruncatedText } from "@/components/erpify";
import type { ListDensity } from "@/components/erpify";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { KeyboardKey } from "@/context/shared/keyboard/domain/KeyboardKey";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { AuditLevel, type AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import { humanizeAuditAction } from "@/context/backoffice/audit/application/humanizeAuditAction";
import { AuditLevelBadge } from "./AuditLevelBadge";
import { ActorChip } from "./ActorChip";
import { ErasedResource } from "./ErasedResource";
import { AuditRowActions, type AuditPivotHandlers } from "./AuditRowActions";

const COLUMN_COUNT = 7;

const ROW_HEIGHTS: Record<ListDensity, string> = { compact: "h-9", comfortable: "h-11" };

const INTERACTIVE_DESCENDANT_SELECTOR =
  "a[href], button, input, select, textarea, [role='button'], [role='link'], [role='menuitem']";

function isFromInteractiveControl(target: EventTarget | null, row: Element): boolean {
  if (!(target instanceof Element) || target === row) return false;
  const control = target.closest(INTERACTIVE_DESCENDANT_SELECTOR);
  return control != null && !control.contains(row);
}

/** A rendered group of rows under one header (a day divider, or a Jornada session). */
export interface AuditTimelineGroup {
  key: string;
  header: ReactNode;
  entries: ReadonlyArray<AuditEntry>;
}

interface AuditTimelineTableProps extends AuditPivotHandlers {
  groups: ReadonlyArray<AuditTimelineGroup>;
  density: ListDensity;
  direction: SortDirection;
  /** When false (Jornada), the "Hora" header is a static label — the sort axis does not apply. */
  sortable?: boolean;
  onToggleSort: () => void;
  onRowActivate: (entry: AuditEntry) => void;
  activeEntryId?: string | null;
  testId?: string;
}

/**
 * The dense investigation timeline, driven by precomputed groups so the SAME table renders the
 * Timeline (per-day dividers) and the Jornada (per-correlation sessions) modes. A real `<table>`:
 * each group is a `<tbody>` (implicit `rowgroup`) labelled by a `<th scope="rowgroup">` header, so a
 * screen reader announces the day / session as group context. One roving tabindex spans the page (no
 * `div role=button` per row — a native table sidesteps the `jsx-a11y` S6847 antipattern): `↑`/`↓`
 * move focus, `Enter` opens the drawer, a click anywhere off an in-row control opens it too.
 * `security` and `change` rows carry a 2px lateral accent — a second channel beside the badge, each a
 * distinct token (`{color.security}` / `{color.brand}`), never `{color.danger}`.
 */
export function AuditTimelineTable({
  groups,
  density,
  direction,
  sortable = true,
  onToggleSort,
  onRowActivate,
  activeEntryId,
  onFollowActor,
  onFollowCorrelation,
  onFollowResource,
  testId,
}: Readonly<AuditTimelineTableProps>) {
  const groupIdBase = useId();
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLTableRowElement | null>>([]);

  // Assign the flat roving-focus index here, in render order, so regrouping (Timeline ↔ Jornada,
  // which reorders rows within a session) never has to re-thread indices upstream.
  const { renderGroups, rowCount } = useMemo(() => {
    let flat = 0;
    const rg = groups.map((group) => ({
      key: group.key,
      header: group.header,
      rows: group.entries.map((entry) => ({ entry, index: flat++ })),
    }));
    return { renderGroups: rg, rowCount: flat };
  }, [groups]);

  // The roving-focus index must always land on a real row: when the page shrinks (a shorter last
  // page, a narrower filter, a Timeline↔Jornada regroup) a stale `focusedRow >= rowCount` would leave
  // every row at `tabIndex=-1`, making the table unreachable by keyboard. Fall back to the first row.
  const activeRow = focusedRow < rowCount ? focusedRow : 0;

  const registerRowRef = useCallback((index: number, el: HTMLTableRowElement | null) => {
    rowRefs.current[index] = el;
  }, []);

  const handleRowKeyDown = useCallback(
    (event: KeyboardEvent<HTMLTableRowElement>, entry: AuditEntry, index: number) => {
      if (isFromInteractiveControl(event.target, event.currentTarget)) return;
      if (event.key === KeyboardKey.ARROW_DOWN || event.key === KeyboardKey.ARROW_UP) {
        event.preventDefault();
        const next =
          event.key === KeyboardKey.ARROW_DOWN
            ? Math.min(index + 1, rowCount - 1)
            : Math.max(index - 1, 0);
        setFocusedRow(next);
        rowRefs.current[next]?.focus();
        return;
      }
      if (event.key === KeyboardKey.ENTER) {
        event.preventDefault();
        onRowActivate(entry);
      }
    },
    [rowCount, onRowActivate],
  );

  const handleRowClick = useCallback(
    (event: MouseEvent<HTMLTableRowElement>, entry: AuditEntry) => {
      if (isFromInteractiveControl(event.target, event.currentTarget)) return;
      onRowActivate(entry);
    },
    [onRowActivate],
  );

  const ariaSort = direction === SortDirection.ASC ? "ascending" : "descending";

  return (
    <div
      className="audit-timeline-table border-border overflow-hidden rounded-md border"
      data-testid={testId}
    >
      <table className="w-full table-fixed border-collapse text-sm" data-density={density}>
        <caption className="sr-only">Timeline de auditoría</caption>
        <colgroup>
          <col className="w-28" />
          <col className="w-24" />
          <col className="w-[22%]" />
          <col className="w-[20%]" />
          <col className="w-[18%]" />
          <col className="w-36" />
          <col className="w-12" />
        </colgroup>
        <thead className="bg-background sticky top-0 z-10">
          <tr className={cn("border-border text-muted-foreground border-b", ROW_HEIGHTS[density])}>
            <th
              scope="col"
              aria-sort={sortable ? ariaSort : undefined}
              className="px-3 text-left text-xs font-medium"
            >
              {sortable ? (
                <button
                  type="button"
                  onClick={onToggleSort}
                  className="hover:text-foreground inline-flex items-center gap-1 transition-colors"
                  title="Ordenar por hora"
                >
                  Hora
                  {direction === SortDirection.ASC ? (
                    <ArrowUp className="size-3" aria-hidden="true" />
                  ) : (
                    <ArrowDown className="size-3" aria-hidden="true" />
                  )}
                </button>
              ) : (
                <span>Hora</span>
              )}
            </th>
            <th scope="col" className="px-3 text-left text-xs font-medium">
              Nivel
            </th>
            <th scope="col" className="px-3 text-left text-xs font-medium">
              Acción
            </th>
            <th scope="col" className="px-3 text-left text-xs font-medium">
              Actor
            </th>
            <th scope="col" className="px-3 text-left text-xs font-medium">
              Recurso
            </th>
            <th scope="col" className="px-3 text-left text-xs font-medium">
              Correlación
            </th>
            <th scope="col" className="px-3 text-right text-xs font-medium">
              <span className="sr-only">Acciones</span>
            </th>
          </tr>
        </thead>
        {renderGroups.map((group, groupIndex) => {
          const headerId = `${groupIdBase}-group-${groupIndex}`;
          return (
            <tbody key={group.key} aria-labelledby={headerId}>
              <tr className="bg-bg-subtle/60 border-border border-b">
                <th
                  id={headerId}
                  scope="rowgroup"
                  colSpan={COLUMN_COUNT}
                  className="text-muted-foreground px-3 py-1.5 text-left text-xs font-semibold"
                >
                  {group.header}
                </th>
              </tr>
              {group.rows.map(({ entry, index }) => {
                const accentClass = levelAccent(entry.level);
                const isActive = activeEntryId === entry.id;
                return (
                  <tr
                    key={entry.id}
                    ref={(el) => registerRowRef(index, el)}
                    tabIndex={activeRow === index ? 0 : -1}
                    aria-current={isActive ? true : undefined}
                    onKeyDown={(event) => handleRowKeyDown(event, entry, index)}
                    onClick={(event) => handleRowClick(event, entry)}
                    onFocus={() => setFocusedRow(index)}
                    data-testid={`audit-timeline__row-${entry.id}`}
                    data-level={entry.level}
                    className={cn(
                      "group/row border-border focus-visible:ring-ring hover:bg-muted cursor-pointer border-b transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:outline-none motion-reduce:transition-none",
                      ROW_HEIGHTS[density],
                      isActive && "bg-muted",
                    )}
                  >
                    <td
                      className={cn(
                        "text-foreground px-3 font-mono text-xs tabular-nums",
                        accentClass,
                      )}
                    >
                      {dateTimeProvider.formatIsoToLocalTimeOfDay(entry.occurredOn)}
                    </td>
                    <td className="px-3">
                      <AuditLevelBadge level={entry.level} />
                    </td>
                    <td className="px-3">
                      <span className="flex flex-col leading-tight">
                        <TruncatedText value={humanizeAuditAction(entry.action)} />
                        <code className="text-text-subtle font-mono text-2xs">{entry.action}</code>
                      </span>
                    </td>
                    <td className="px-3">
                      <ActorChip
                        actorType={entry.actorType}
                        actorId={entry.actorId}
                        actorErased={entry.actorErased}
                      />
                    </td>
                    <td className="text-muted-foreground px-3 text-xs">
                      <ResourceCell
                        type={entry.resourceType}
                        id={entry.resourceId}
                        erased={entry.resourceErased}
                      />
                    </td>
                    <td className="px-3">
                      <CorrelationIdChip id={entry.correlationId} />
                    </td>
                    <td className="px-3 text-right">
                      <AuditRowActions
                        entry={entry}
                        onFollowActor={onFollowActor}
                        onFollowCorrelation={onFollowCorrelation}
                        onFollowResource={onFollowResource}
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          );
        })}
      </table>
    </div>
  );
}

/** The 2px lateral row accent — a second channel beside the badge; only `security`/`change` carry one. */
function levelAccent(level: string): string {
  if (level === AuditLevel.Security) return "border-l-security border-l-2";
  if (level === AuditLevel.Change) return "border-l-brand border-l-2";
  return "";
}

/**
 * `resourceType · resourceId` (id copyable, never a deep-link), the anonymized state when the axis has
 * been erased, or «—» when the record references neither.
 */
function ResourceCell({
  type,
  id,
  erased,
}: Readonly<{ type: string | null; id: string | null; erased: boolean }>) {
  if (!type && !id) return <span className="text-text-subtle">—</span>;
  if (erased) return <ErasedResource resourceType={type} />;
  return (
    <span className="inline-flex items-center gap-1">
      {type ? <span>{type}</span> : null}
      {id ? (
        <span className="inline-flex items-center gap-1">
          {type ? <span className="text-text-subtle">·</span> : null}
          <code className="font-mono" title={id}>
            {id.length > 13 ? `${id.slice(0, 6)}…${id.slice(-4)}` : id}
          </code>
          <CopyButton
            value={id}
            iconOnly
            size="sm"
            variant="ghost"
            label="Copiar id de recurso"
            title="Copiar id de recurso"
          />
        </span>
      ) : null}
    </span>
  );
}
