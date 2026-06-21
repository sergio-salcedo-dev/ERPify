"use client";

import { Bank, type BankPrimitives } from "@/context/backoffice/bank/domain/Bank";
import { realtimeScope } from "@/context/shared/observability/domain/TelemetryScope";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";
import { useMercureRealtime } from "@/context/shared/real-time/infrastructure/useMercureRealtime";

/**
 * Mercure topic IRIs for back-office banks. MUST stay in lock-step with the API
 * `Erpify\Backoffice\Bank\Domain\MercureBankTopic`.
 */
export const bankTopics = {
  collection: "urn:erpify:backoffice:banks",
  detail: (id: string): string => `urn:erpify:backoffice:bank:${id}`,
} as const;

export type BankRealtimeEvent =
  | { kind: "created"; bank: Bank }
  | { kind: "updated"; bank: Bank }
  | { kind: "deleted"; id: string };

export interface BankRealtimeHandlers {
  onCreated?: (bank: Bank) => void;
  onUpdated?: (bank: Bank) => void;
  onDeleted?: (id: string) => void;
  /**
   * Called when the stream re-opens after a drop (never on the initial connect).
   * Refetch the list / detail here to reconcile updates missed during the gap.
   */
  onReconnect?: () => void;
}

/** A count is valid only as a non-negative integer; everything else normalizes to 0. */
function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value >= 0;
}

/**
 * Validates the identity/lifecycle core of a bank payload and normalizes it to
 * `BankPrimitives`. `accountCount` is a display-only signal: a payload from a
 * pre-`accountCount` API pod (rolling deploy) or a replayed older event may omit
 * it, so a missing or non-conforming value (not a non-negative integer) defaults
 * to 0 rather than dropping the whole event — losing a created/updated delivery
 * over a cosmetic field is the worse failure. The next list/detail fetch
 * reconciles the count (stale-tolerant).
 */
function toBankPrimitives(value: unknown): BankPrimitives | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  const v = value as Record<string, unknown>;
  if (
    typeof v.id !== "string" ||
    typeof v.name !== "string" ||
    typeof v.shortName !== "string" ||
    typeof v.createdAt !== "string" ||
    typeof v.updatedAt !== "string"
  ) {
    return null;
  }
  return {
    id: v.id,
    name: v.name,
    shortName: v.shortName,
    createdAt: v.createdAt,
    updatedAt: v.updatedAt,
    accountCount: isNonNegativeInteger(v.accountCount) ? v.accountCount : 0,
  };
}

/** Parses a raw Mercure payload into a typed bank event, or null when unusable. */
export function parseBankRealtimeEvent(data: unknown): BankRealtimeEvent | null {
  if (typeof data !== "object" || data === null || !("type" in data)) {
    return null;
  }
  const payload = data as { type: unknown; bank?: unknown; id?: unknown };
  switch (payload.type) {
    case "bank.created": {
      const bank = toBankPrimitives(payload.bank);
      return bank ? { kind: "created", bank: Bank.fromPrimitives(bank) } : null;
    }
    case "bank.updated": {
      const bank = toBankPrimitives(payload.bank);
      return bank ? { kind: "updated", bank: Bank.fromPrimitives(bank) } : null;
    }
    case "bank.deleted":
      return typeof payload.id === "string" ? { kind: "deleted", id: payload.id } : null;
    default:
      return null;
  }
}

/**
 * Subscribes to the given bank Mercure topics and dispatches typed events to the
 * provided handlers. Delegates authorize / subscribe / telemetry to the shared
 * {@link useMercureRealtime} hook; this wrapper only owns the bank-specific
 * topic parse + handler mapping.
 */
export function useBankRealtime(topics: readonly string[], handlers: BankRealtimeHandlers): void {
  useMercureRealtime<BankRealtimeEvent>({
    topics,
    authorizePath: API_ENDPOINTS.BACKOFFICE.BANKS.REALTIME_AUTHORIZE,
    parse: parseBankRealtimeEvent,
    onEvent: (event) => {
      if (event.kind === "created") {
        handlers.onCreated?.(event.bank);
      } else if (event.kind === "updated") {
        handlers.onUpdated?.(event.bank);
      } else {
        handlers.onDeleted?.(event.id);
      }
    },
    onReconnect: handlers.onReconnect,
    scope: realtimeScope("bank"),
  });
}
