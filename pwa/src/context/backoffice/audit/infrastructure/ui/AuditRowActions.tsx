"use client";

import { Footprints, GitBranch, MoreHorizontal, Package } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

export interface AuditPivotHandlers {
  onFollowActor?: (entry: AuditEntry) => void;
  onFollowCorrelation?: (entry: AuditEntry) => void;
  onFollowResource?: (entry: AuditEntry) => void;
}

interface AuditRowActionsProps extends AuditPivotHandlers {
  entry: AuditEntry;
}

/**
 * The per-row `⋯` overflow holding the investigation **pivots** — each re-filters the same
 * `audit_log` (a safe query), never a write. Copy lives on the inline chips (actor id, resource id,
 * correlation), so the menu stays pivots-only and clipboard stays inside `<CopyButton>`. A pivot is
 * offered only when it has a target, and an erased axis has none: no follow-actor for an anonymized
 * actor and no follow-resource for an anonymized resource — the random UUID left on either column is
 * not identity, so filtering by it would present a reference denoting nobody as a live one — and no
 * follow-resource without a resource at all. Renders nothing when no pivot applies.
 */
export function AuditRowActions({
  entry,
  onFollowActor,
  onFollowCorrelation,
  onFollowResource,
}: Readonly<AuditRowActionsProps>) {
  const canFollowActor = Boolean(onFollowActor) && entry.actorId !== null && !entry.actorErased;
  const canFollowCorrelation = Boolean(onFollowCorrelation);
  const canFollowResource =
    Boolean(onFollowResource) &&
    (entry.resourceType !== null || entry.resourceId !== null) &&
    !entry.resourceErased;

  if (!canFollowActor && !canFollowCorrelation && !canFollowResource) return null;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label="Más acciones"
            title="Más acciones"
            data-testid={`audit-row-actions__trigger-${entry.id}`}
          >
            <MoreHorizontal className="size-3.5" aria-hidden="true" />
            <span className="sr-only">Más acciones</span>
          </Button>
        }
      />
      <DropdownMenuContent align="end" className="min-w-56">
        {canFollowActor ? (
          <DropdownMenuItem
            onClick={() => onFollowActor?.(entry)}
            data-testid={`audit-row-actions__follow-actor-${entry.id}`}
          >
            <Footprints aria-hidden="true" />
            Seguir a este actor
          </DropdownMenuItem>
        ) : null}
        {canFollowCorrelation ? (
          <DropdownMenuItem
            onClick={() => onFollowCorrelation?.(entry)}
            data-testid={`audit-row-actions__follow-correlation-${entry.id}`}
          >
            <GitBranch aria-hidden="true" />
            Ver esta correlación
          </DropdownMenuItem>
        ) : null}
        {canFollowResource ? (
          <DropdownMenuItem
            onClick={() => onFollowResource?.(entry)}
            data-testid={`audit-row-actions__follow-resource-${entry.id}`}
          >
            <Package aria-hidden="true" />
            Ver actividad de este recurso
          </DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
