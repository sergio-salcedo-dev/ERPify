"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import {
  AsyncBoundary,
  DensityToggle,
  EmptyState,
  LIST_DENSITY_STORAGE_KEY,
  isListDensity,
} from "@/components/erpify";
import { useStoredPreference } from "@/context/shared/storage/infrastructure/useStoredPreference";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import { useAuditTimeline } from "@/context/backoffice/audit/application/useAuditTimeline";
import { AuditFilterBar } from "@/context/backoffice/audit/infrastructure/ui/AuditFilterBar";
import {
  AuditTimelineTable,
  type AuditTimelineGroup,
} from "@/context/backoffice/audit/infrastructure/ui/AuditTimelineTable";
import { AuditTimelineSkeleton } from "@/context/backoffice/audit/infrastructure/ui/AuditTimelineSkeleton";
import { AuditPagination } from "@/context/backoffice/audit/infrastructure/ui/AuditPagination";
import { AuditEntryDrawer } from "@/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer";
import { AuditViewToggle } from "@/context/backoffice/audit/infrastructure/ui/AuditViewToggle";
import { JourneySessionHeader } from "@/context/backoffice/audit/infrastructure/ui/JourneySessionHeader";
import { AuditView, hasActiveAuditFilter, hasFixedActor } from "../_lib/auditFilter";
import { toAuditCriteria } from "../_lib/auditSearchCriteria";
import { groupEntriesByDay } from "../_lib/auditDayGroups";
import { groupEntriesByCorrelation } from "../_lib/auditJourneyGroups";
import { AUDIT_PAGE_SIZE_DEFAULT, type AuditPageSize } from "../_lib/auditPaginate";
import { useAuditUrlState } from "../_lib/auditUrlState";

/** Day-divider groups (Timeline) or correlation sessions (Jornada) for the grouped timeline table. */
function buildGroups(entries: ReadonlyArray<AuditEntry>, view: AuditView): AuditTimelineGroup[] {
  if (view === AuditView.Journey) {
    return groupEntriesByCorrelation(entries).map((session) => ({
      key: session.correlationId,
      header: <JourneySessionHeader group={session} />,
      entries: session.entries,
    }));
  }
  return groupEntriesByDay(entries, (iso) => dateTimeProvider.formatIsoToLongDate(iso)).map(
    (day) => ({ key: day.label, header: day.label, entries: day.entries }),
  );
}

/**
 * The single audit investigation screen: filter bar + timeline + drawer, driven entirely by URL
 * state (shareable, no PII in storage). Read-only — there is no mutation surface (D5). The drawer
 * opens for the row deep-linked by `?entry`; an off-page deep link cannot resolve until the 4.2a
 * detail endpoint lands (deferred until auth), so it silently no-ops rather than guessing.
 */
export function AuditInvestigationScreen() {
  const url = useAuditUrlState();
  const [density, setDensity] = useStoredPreference(
    LIST_DENSITY_STORAGE_KEY,
    "compact",
    isListDensity,
  );
  const [pageSize, setPageSize] = useState<AuditPageSize>(AUDIT_PAGE_SIZE_DEFAULT);

  // Jornada is reachable only with a fixed actor; a stale `view=journey` URL with no actor falls back
  // to Timeline so the toggle and the rendered grouping never disagree.
  const journeyEnabled = hasFixedActor(url.filter);
  const journeyMode = journeyEnabled && url.view === AuditView.Journey;
  const effectiveView = journeyMode ? AuditView.Journey : AuditView.Timeline;

  // The "Hora" sort axis does not apply to Jornada: a session is intrinsically ordered and the
  // sessions themselves always read newest-first. So Jornada fetches DESC regardless of the URL `dir`
  // and the column header drops its toggle (see `sortable` below).
  const sortDirection = journeyMode ? SortDirection.DESC : url.direction;

  const criteria = useMemo(
    () => toAuditCriteria(url.filter, sortDirection, pageSize),
    [url.filter, sortDirection, pageSize],
  );
  const hasActiveFilter = hasActiveAuditFilter(url.filter);

  const { state, entries, paginationActions, problem, reload } = useAuditTimeline({
    criteria,
    hasActiveFilter,
  });

  const toggleSort = (): void =>
    url.setDirection(url.direction === SortDirection.DESC ? SortDirection.ASC : SortDirection.DESC);

  const activeEntry = url.entry ? (entries.find((entry) => entry.id === url.entry) ?? null) : null;

  const groups = useMemo(() => buildGroups(entries, effectiveView), [entries, effectiveView]);

  const followActor = (entry: AuditEntry): void =>
    url.patchFilter({ actorType: entry.actorType, actorId: entry.actorId ?? "" });
  const followCorrelation = (entry: AuditEntry): void =>
    url.patchFilter({ correlationId: entry.correlationId });
  const followResource = (entry: AuditEntry): void =>
    url.patchFilter({ resourceType: entry.resourceType ?? "", resourceId: entry.resourceId ?? "" });

  return (
    <div className="audit-investigation flex flex-col gap-4">
      <header className="audit-investigation__header flex flex-col gap-1">
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Auditoría</h1>
        <p className="text-muted-foreground text-sm">
          Investiga la actividad y la seguridad registradas.
        </p>
      </header>

      <AuditFilterBar
        filter={url.filter}
        onPatch={url.patchFilter}
        onReset={url.reset}
        leading={
          <DensityToggle density={density} onDensityChange={setDensity} testId="audit-density" />
        }
      />

      <AuditViewToggle
        view={effectiveView}
        onViewChange={url.setView}
        journeyEnabled={journeyEnabled}
      />

      <AsyncBoundary
        state={state}
        data={entries}
        error={problem ?? undefined}
        loading={
          <AuditTimelineSkeleton rows={Math.min(pageSize, 8)} testId="audit-timeline-skeleton" />
        }
        emptyVariant="first-run"
        emptyHeading="Sin actividad registrada"
        emptyDescription="Aún no hay entradas en el registro de auditoría."
        errorAction={
          <Button type="button" variant="outline" size="sm" onClick={() => reload()}>
            Reintentar
          </Button>
        }
      >
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              variant="filtered-to-zero"
              heading="Ningún resultado"
              description="Ninguna entrada coincide con estos filtros."
              action={
                <Button type="button" variant="outline" size="sm" onClick={url.reset}>
                  Limpiar filtros
                </Button>
              }
            />
          ) : (
            <>
              <AuditTimelineTable
                groups={groups}
                density={density}
                direction={sortDirection}
                sortable={!journeyMode}
                onToggleSort={toggleSort}
                onRowActivate={(entry) => url.openEntry(entry.id)}
                activeEntryId={url.entry}
                onFollowActor={followActor}
                onFollowCorrelation={followCorrelation}
                onFollowResource={followResource}
                testId="audit-timeline"
              />
              <AuditPagination
                pageSize={pageSize}
                hasPrev={paginationActions.hasPrev}
                hasNext={paginationActions.hasNext}
                onPrev={paginationActions.goPrev}
                onNext={paginationActions.goNext}
                onPageSizeChange={setPageSize}
              />
            </>
          )
        }
      </AsyncBoundary>

      <AuditEntryDrawer
        entry={activeEntry}
        open={activeEntry !== null}
        onClose={url.closeEntry}
        onFollowActor={followActor}
        onFollowCorrelation={followCorrelation}
        onFollowResource={followResource}
      />
    </div>
  );
}
