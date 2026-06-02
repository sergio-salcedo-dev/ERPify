"use client";

import { Bank, type BankPrimitives } from "@/context/backoffice/bank/domain/Bank";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";
import { useMercureRealtime } from "@/context/shared/infrastructure/RealTime/useMercureRealtime";

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
}

function isBankPrimitives(value: unknown): value is BankPrimitives {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const v = value as Record<string, unknown>;
  return (
    typeof v.id === "string" &&
    typeof v.name === "string" &&
    typeof v.shortName === "string" &&
    typeof v.createdAt === "string" &&
    typeof v.updatedAt === "string"
  );
}

/** Parses a raw Mercure payload into a typed bank event, or null when unusable. */
export function parseBankRealtimeEvent(data: unknown): BankRealtimeEvent | null {
  if (typeof data !== "object" || data === null || !("type" in data)) {
    return null;
  }
  const payload = data as { type: unknown; bank?: unknown; id?: unknown };
  switch (payload.type) {
    case "bank.created":
      return isBankPrimitives(payload.bank)
        ? { kind: "created", bank: Bank.fromPrimitives(payload.bank) }
        : null;
    case "bank.updated":
      return isBankPrimitives(payload.bank)
        ? { kind: "updated", bank: Bank.fromPrimitives(payload.bank) }
        : null;
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
    scope: "realtime:bank",
  });
}
