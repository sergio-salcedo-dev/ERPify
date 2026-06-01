"use client";

import { useEffect, useEffectEvent } from "react";
import { Bank, type BankPrimitives } from "@/context/backoffice/bank/domain/Bank";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";
import { mercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

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

async function authorize(): Promise<void> {
  // Resolve an absolute URL against the current origin so `fetch` works the same
  // way the EventSource subscription does (a bare relative path is unparseable
  // outside a browser, e.g. under test/SSR).
  const base = (process.env.NEXT_PUBLIC_SYMFONY_API_BASE_URL ?? "").replace(/\/$/, "");
  const origin = globalThis.window?.location.origin ?? "http://localhost";
  const url = new URL(`${base}${API_ENDPOINTS.BACKOFFICE.BANKS.REALTIME_AUTHORIZE}`, origin);
  await fetch(url, { credentials: "include", cache: "no-store" });
}

/**
 * Subscribes to the given bank Mercure topics and dispatches typed events to the
 * provided handlers. Authorizes (mints the subscriber cookie) before opening the
 * stream. No-op on the server and when `topics` is empty.
 */
export function useBankRealtime(topics: readonly string[], handlers: BankRealtimeHandlers): void {
  // `topicsKey` is the only effect dependency: a stable primitive that changes
  // exactly when the set of topics changes (topic IRIs never contain "|"). This
  // keeps the EventSource open across unrelated re-renders.
  const topicsKey = topics.join("|");

  // Effect Event: always sees the latest `handlers` without being a dependency,
  // so changing handler identity each render never tears down the stream.
  const dispatch = useEffectEvent((data: unknown): void => {
    const event = parseBankRealtimeEvent(data);
    if (!event) {
      return;
    }
    if (event.kind === "created") {
      handlers.onCreated?.(event.bank);
    } else if (event.kind === "updated") {
      handlers.onUpdated?.(event.bank);
    } else {
      handlers.onDeleted?.(event.id);
    }
  });

  useEffect(() => {
    if (!topicsKey || globalThis.window === undefined) {
      return;
    }

    const topicList = topicsKey.split("|");
    let subscription: { close(): void } | undefined;
    let cancelled = false;

    void (async (): Promise<void> => {
      try {
        await authorize();
        if (!cancelled) {
          subscription = mercureSubscriber.subscribe(topicList, (data) => dispatch(data), {
            // On a stream error the subscriber cookie may have expired; re-mint
            // it so the EventSource's automatic reconnect is authorized again.
            onError: () => void authorize().catch(() => {}),
          });
        }
      } catch {
        // Best-effort: a missing cookie, an absent EventSource (SSR/test), or a
        // transient network error must never surface as an unhandled rejection.
      }
    })();

    return (): void => {
      cancelled = true;
      subscription?.close();
    };
  }, [topicsKey]);
}
