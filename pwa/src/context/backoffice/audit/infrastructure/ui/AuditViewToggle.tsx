"use client";

import { useId } from "react";
import { cn } from "@/components/cn";
import { AuditView } from "@/app/backoffice/audit/_lib/auditFilter";

interface AuditViewToggleProps {
  view: AuditView;
  onViewChange: (view: AuditView) => void;
  /** Journey is only reachable once an actor is fixed (UX-DR3). */
  journeyEnabled: boolean;
}

/**
 * The render-mode toggle. «Journey» is gated on a fixed actor: when unavailable it is
 * `aria-disabled` (not the native `disabled`, which hides the reason from keyboard/SR) with
 * `aria-describedby` pointing at a **visible** hint — so the "why" reaches everyone, not only a hover.
 */
export function AuditViewToggle({
  view,
  onViewChange,
  journeyEnabled,
}: Readonly<AuditViewToggleProps>) {
  const hintId = useId();

  return (
    <div className="audit-view-toggle flex items-center gap-2" data-testid="audit-view-toggle">
      <div
        role="radiogroup"
        aria-label="View mode"
        className="border-border inline-flex rounded-md border p-0.5"
      >
        <ToggleOption
          label="Timeline"
          active={view === AuditView.Timeline}
          onSelect={() => onViewChange(AuditView.Timeline)}
          testId="audit-view-toggle__timeline"
        />
        <button
          type="button"
          role="radio"
          aria-checked={view === AuditView.Journey}
          aria-disabled={!journeyEnabled || undefined}
          aria-describedby={journeyEnabled ? undefined : hintId}
          onClick={() => {
            if (journeyEnabled) onViewChange(AuditView.Journey);
          }}
          className={cn(
            "rounded px-3 py-1 text-xs font-medium transition-colors",
            view === AuditView.Journey
              ? "bg-primary text-primary-foreground"
              : "text-muted-foreground hover:text-foreground",
            !journeyEnabled && "cursor-not-allowed opacity-50",
          )}
          data-testid="audit-view-toggle__journey"
        >
          Journey
        </button>
      </div>
      {!journeyEnabled && (
        <span
          id={hintId}
          className="text-text-subtle text-xs"
          data-testid="audit-view-toggle__hint"
        >
          Pin an actor to reconstruct their journey.
        </span>
      )}
    </div>
  );
}

function ToggleOption({
  label,
  active,
  onSelect,
  testId,
}: Readonly<{ label: string; active: boolean; onSelect: () => void; testId: string }>) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={active}
      onClick={onSelect}
      className={cn(
        "rounded px-3 py-1 text-xs font-medium transition-colors",
        active
          ? "bg-primary text-primary-foreground"
          : "text-muted-foreground hover:text-foreground",
      )}
      data-testid={testId}
    >
      {label}
    </button>
  );
}
