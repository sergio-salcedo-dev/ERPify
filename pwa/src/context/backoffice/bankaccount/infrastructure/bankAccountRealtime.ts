"use client";

import { realtimeScope } from "@/context/shared/observability/domain/TelemetryScope";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { useMercureRealtime } from "@/context/shared/real-time/infrastructure/useMercureRealtime";

/**
 * Mercure topic IRIs for back-office bank accounts. MUST stay in lock-step with
 * the API `Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic`. The
 * collection is scoped per owning bank (the list is per-bank); the detail is
 * keyed by the account id.
 */
export const bankAccountTopics = {
  collection: (bankId: string): string => `urn:erpify:backoffice:bank:${bankId}:accounts`,
  detail: (accountId: string): string => `urn:erpify:backoffice:bankaccount:${accountId}`,
} as const;

/**
 * The bank-account broadcast is PII-minimal by design: it carries only the
 * lifecycle `kind` plus the account `id` and owning `bankId` — never IBAN /
 * holderName / bic / alias. The UI reacts by REFETCHING the authenticated list,
 * never by reading account data off the payload (the IBAN exists only on the
 * read, masked at the edge).
 */
export type BankAccountRealtimeEvent = {
  kind: "created" | "updated" | "deleted";
  id: string;
  bankId: string;
};

export interface BankAccountRealtimeHandlers {
  onCreated?: (event: BankAccountRealtimeEvent) => void;
  onUpdated?: (event: BankAccountRealtimeEvent) => void;
  onDeleted?: (event: BankAccountRealtimeEvent) => void;
  /**
   * Called when the stream re-opens after a drop (never on the initial connect).
   * Refetch the list / detail here to reconcile changes missed during the gap.
   */
  onReconnect?: () => void;
}

const EVENT_KIND: Record<string, BankAccountRealtimeEvent["kind"]> = {
  "bank_account.created": "created",
  "bank_account.updated": "updated",
  "bank_account.deleted": "deleted",
};

/** Parses a raw Mercure payload into a typed minimal event, or null when unusable. */
export function parseBankAccountRealtimeEvent(data: unknown): BankAccountRealtimeEvent | null {
  if (typeof data !== "object" || data === null) {
    return null;
  }
  const payload = data as { type?: unknown; id?: unknown; bankId?: unknown };
  if (typeof payload.type !== "string") {
    return null;
  }
  const kind = EVENT_KIND[payload.type];
  if (kind === undefined || typeof payload.id !== "string" || typeof payload.bankId !== "string") {
    return null;
  }
  return { kind, id: payload.id, bankId: payload.bankId };
}

/**
 * Subscribes to the given bank-account Mercure topics and dispatches typed
 * events to the provided handlers. Delegates authorize / subscribe / telemetry
 * to the shared {@link useMercureRealtime} hook; this wrapper only owns the
 * minimal payload parse + handler mapping. Consumers refetch on every event.
 */
export function useBankAccountRealtime(
  topics: readonly string[],
  handlers: BankAccountRealtimeHandlers,
): void {
  useMercureRealtime<BankAccountRealtimeEvent>({
    topics,
    authorizePath: API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.REALTIME_AUTHORIZE,
    parse: parseBankAccountRealtimeEvent,
    onEvent: (event) => {
      if (event.kind === "created") {
        handlers.onCreated?.(event);
      } else if (event.kind === "updated") {
        handlers.onUpdated?.(event);
      } else {
        handlers.onDeleted?.(event);
      }
    },
    onReconnect: handlers.onReconnect,
    scope: realtimeScope("bank-account"),
  });
}
