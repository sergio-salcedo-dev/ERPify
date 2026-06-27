"use client";

import { useEffect, useId, useState, type ReactNode } from "react";
import { SlidersHorizontal } from "lucide-react";
import { cn } from "@/components/cn";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { DateField, FormField } from "@/components/erpify";
import { useDebouncedValue } from "@/context/shared/search/infrastructure/useDebouncedValue";
import { ActorType } from "@/context/backoffice/audit/domain/AuditEntry";
import {
  AUDIT_LEVEL_SEGMENTS,
  countPanelFilters,
  hasActiveAuditFilter,
  type AuditFilter,
} from "@/app/backoffice/audit/_lib/auditFilter";

const FILTER_DEBOUNCE_MS = 250;

/** The debounced text axes (id/text inputs); level and actorType push immediately (click/select). */
interface TextDraft {
  from: string;
  to: string;
  actorId: string;
  resourceType: string;
  resourceId: string;
  action: string;
}

const ACTOR_TYPE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: "", label: "Cualquiera" },
  { value: ActorType.User, label: "user" },
  { value: ActorType.ApiKey, label: "api_key" },
  { value: ActorType.System, label: "system" },
  { value: ActorType.Anonymous, label: "anonymous" },
];

const SELECT_CLASS =
  "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none";

interface AuditFilterBarProps {
  filter: AuditFilter;
  onPatch: (patch: Partial<AuditFilter>) => void;
  onReset: () => void;
  /** Optional leading controls (density / view toggle) shared on the toolbar row. */
  leading?: ReactNode;
}

function textDraftOf(filter: AuditFilter): TextDraft {
  return {
    from: filter.from,
    to: filter.to,
    actorId: filter.actorId,
    resourceType: filter.resourceType,
    resourceId: filter.resourceId,
    action: filter.action,
  };
}

/**
 * The audit filter surface: an always-visible bar (segmented level + inclusive date range + a
 * collapsible-panel toggle with an active-count badge) over a panel holding the lower-frequency axes
 * (actor type/id, resource type/id, action). All state is owned upstream in URL params; this is pure
 * representation. Text axes are debounced so typing stays instant and the cursor only resets once the
 * value settles; level and actor type commit immediately. The collapsible inline panel mirrors the
 * established list-filter pattern (accessible `aria-expanded`/`inert`), not a portal popover.
 */
export function AuditFilterBar({
  filter,
  onPatch,
  onReset,
  leading,
}: Readonly<AuditFilterBarProps>) {
  const panelId = useId();
  const panelCount = countPanelFilters(filter);
  const [open, setOpen] = useState<boolean>(panelCount > 0);

  // Local mirror of the text axes: typing stays instant while the URL write (and cursor reset) is
  // debounced. Re-seeded via the getDerivedState-during-render idiom when the URL changes externally
  // (Reset, a pivot) so external state always wins.
  const [draft, setDraft] = useState<TextDraft>(() => textDraftOf(filter));
  const externalKey = JSON.stringify(textDraftOf(filter));
  const [prevExternalKey, setPrevExternalKey] = useState(externalKey);
  if (externalKey !== prevExternalKey) {
    setPrevExternalKey(externalKey);
    setDraft(textDraftOf(filter));
  }

  const debounced = useDebouncedValue(draft, FILTER_DEBOUNCE_MS);
  useEffect(() => {
    const patch: Partial<AuditFilter> = {};
    for (const key of Object.keys(debounced) as Array<keyof TextDraft>) {
      if (debounced[key] !== filter[key]) patch[key] = debounced[key];
    }
    if (Object.keys(patch).length > 0) onPatch(patch);
    // Only the debounced value drives this; `filter`/`onPatch` are read as the latest closure.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  const setText = (field: keyof TextDraft, value: string): void =>
    setDraft((prev) => ({ ...prev, [field]: value }));

  const handleReset = (): void => {
    setDraft(
      textDraftOf({
        ...filter,
        from: "",
        to: "",
        actorId: "",
        resourceType: "",
        resourceId: "",
        action: "",
      }),
    );
    onReset();
  };

  const canReset = hasActiveAuditFilter(filter);
  const toggleLabel = panelCount > 0 ? `Filtros, ${panelCount} activos` : "Filtros";

  return (
    <section
      className="audit-filter-bar"
      aria-label="Filtros de auditoría"
      data-testid="audit-filter-bar"
    >
      <div className="audit-filter-bar__toolbar flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        {leading ? (
          <div className="audit-filter-bar__leading flex items-center">{leading}</div>
        ) : null}

        <div
          role="radiogroup"
          aria-label="Nivel"
          className="audit-filter-bar__level border-border inline-flex rounded-md border p-0.5"
          data-testid="audit-filter-bar__level"
        >
          {AUDIT_LEVEL_SEGMENTS.map((segment) => {
            const active = filter.level === segment.value;
            return (
              <button
                key={segment.value || "all"}
                type="button"
                role="radio"
                aria-checked={active}
                onClick={() => onPatch({ level: segment.value })}
                className={cn(
                  "rounded px-3 py-1 text-xs font-medium transition-colors",
                  active
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:text-foreground",
                )}
                data-testid={`audit-filter-bar__level-${segment.value || "all"}`}
              >
                {segment.label}
              </button>
            );
          })}
        </div>

        <div className="audit-filter-bar__dates flex items-end gap-2">
          <DateField
            name="audit-filter-from"
            label="Desde"
            value={draft.from}
            onChange={(next) => setText("from", next)}
            testId="audit-filter-bar__from"
            className="w-36"
          />
          <DateField
            name="audit-filter-to"
            label="Hasta"
            value={draft.to}
            onChange={(next) => setText("to", next)}
            testId="audit-filter-bar__to"
            className="w-36"
          />
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          aria-expanded={open}
          aria-controls={panelId}
          aria-label={toggleLabel}
          title={toggleLabel}
          className="audit-filter-bar__toggle"
          data-testid="audit-filter-bar__toggle"
        >
          <SlidersHorizontal className="size-3.5" aria-hidden="true" />
          <span>Filtros</span>
          {panelCount > 0 ? (
            <span
              className="bg-primary text-primary-foreground ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium"
              aria-hidden="true"
              data-testid="audit-filter-bar__count"
            >
              {panelCount}
            </span>
          ) : null}
        </Button>

        {canReset ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleReset}
            className="audit-filter-bar__reset"
            data-testid="audit-filter-bar__reset"
            title="Limpiar filtros"
          >
            Limpiar filtros
          </Button>
        ) : null}
      </div>

      <section
        id={panelId}
        aria-label="Filtros avanzados de auditoría"
        aria-hidden={!open}
        inert={open ? undefined : true}
        className="audit-filter-bar__panel grid transition-[grid-template-rows] duration-200 ease-out"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
        data-testid="audit-filter-bar__panel"
      >
        <div className="overflow-hidden">
          <div className="border-border bg-muted/20 mt-3 grid grid-cols-1 gap-3 rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-3">
            <FormField name="audit-filter-actor-type" label="Tipo de actor">
              <select
                className={SELECT_CLASS}
                value={filter.actorType}
                onChange={(event) => onPatch({ actorType: event.target.value })}
                aria-label="Tipo de actor"
                data-testid="audit-filter-bar__actor-type"
              >
                {ACTOR_TYPE_OPTIONS.map((option) => (
                  <option key={option.value || "any"} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField name="audit-filter-actor-id" label="Id de actor">
              <Input
                type="text"
                value={draft.actorId}
                onChange={(event) => setText("actorId", event.target.value)}
                placeholder="UUID del actor"
                data-testid="audit-filter-bar__actor-id"
              />
            </FormField>
            <FormField name="audit-filter-action" label="Acción">
              <Input
                type="text"
                value={draft.action}
                onChange={(event) => setText("action", event.target.value)}
                placeholder="p. ej. ACCESS_DENIED"
                data-testid="audit-filter-bar__action"
              />
            </FormField>
            <FormField name="audit-filter-resource-type" label="Tipo de recurso">
              <Input
                type="text"
                value={draft.resourceType}
                onChange={(event) => setText("resourceType", event.target.value)}
                placeholder="p. ej. BankAccount"
                data-testid="audit-filter-bar__resource-type"
              />
            </FormField>
            <FormField name="audit-filter-resource-id" label="Id de recurso">
              <Input
                type="text"
                value={draft.resourceId}
                onChange={(event) => setText("resourceId", event.target.value)}
                placeholder="UUID del recurso"
                data-testid="audit-filter-bar__resource-id"
              />
            </FormField>
          </div>
        </div>
      </section>
    </section>
  );
}
