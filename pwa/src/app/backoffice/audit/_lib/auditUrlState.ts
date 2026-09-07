"use client";

import { useCallback, useMemo } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { EMPTY_AUDIT_FILTER, isAuditLevelValue, type AuditFilter } from "./auditFilter";

const DIR_PARAM = "dir";
const ENTRY_PARAM = "entry";

/** Filter axes, in a fixed order so the serialized URL is deterministic and the memo key is stable. */
const FILTER_KEYS: ReadonlyArray<keyof AuditFilter> = [
  "level",
  "from",
  "to",
  "actorType",
  "actorId",
  "resourceType",
  "resourceId",
  "action",
  "correlationId",
];

export interface AuditUrlState {
  filter: AuditFilter;
  direction: SortDirection;
  entry: string | null;
  setFilter: (next: AuditFilter) => void;
  patchFilter: (patch: Partial<AuditFilter>) => void;
  setDirection: (direction: SortDirection) => void;
  openEntry: (id: string) => void;
  closeEntry: () => void;
  reset: () => void;
}

/**
 * Single source of truth for the audit screen's state, held entirely in URL params. `actorId` and
 * `resourceId` identify a person, and they are in the address bar on purpose: that is what makes an
 * investigation shareable in a ticket and reachable by deep link, and it is why none of them is ever
 * written to device storage the app controls. The price is that they travel on every navigation, into
 * logs that no erasure path reaches, so each sink is answered where it lives: Caddy's access log
 * redacts both names and the `filters[N][value]` grammar this screen's API request repeats them in,
 * and drops the `Referer` that would otherwise reproduce this whole URL on every same-origin call
 * (`api/frankenphp/Caddyfile`); the application log redacts the same axes wherever a `request_uri`
 * appears; and Sentry's event is scrubbed on `url`, `query_string` and `Referer` before it leaves the
 * process.
 *
 * This route also answers `Referrer-Policy: no-referrer`, but note what that does and does not buy:
 * the policy is delivered with a DOCUMENT, so it applies to a deep link or a refresh and not to a
 * client-side navigation here from elsewhere in the back-office, where the initial document's
 * `strict-origin-when-cross-origin` still governs and same-origin requests carry the whole URL. The
 * header is defence in depth; what actually closes the log is the edge dropping the header.
 *
 * One sink stays OPEN, and is recorded rather than claimed closed: the Next.js container prints the
 * full document URL to the same unowned driver, which Caddy cannot reach because it is a different
 * process. `PRODUCTION_SECURITY_CHECKLIST.md` §7 carries it, together with the accepted residual of a
 * person id in a URL path.
 *
 * Reads decode the params; writes re-serialize the whole decoded state, so a stale param can never
 * linger. Defaults (empty filters, DESC, no open entry) are omitted from the URL to
 * keep it minimal and the memo key stable — the latter matters because the timeline hook resets its
 * keyset cursor whenever the filter value changes, so a flapping identity would thrash pagination.
 */
export function useAuditUrlState(): AuditUrlState {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const paramsKey = searchParams.toString();

  const filter = useMemo<AuditFilter>(() => {
    const params = new URLSearchParams(paramsKey);
    const read = (key: keyof AuditFilter): string => params.get(key)?.trim() ?? "";
    const level = read("level");
    return {
      level: isAuditLevelValue(level) ? level : "",
      from: read("from"),
      to: read("to"),
      actorType: read("actorType"),
      actorId: read("actorId"),
      resourceType: read("resourceType"),
      resourceId: read("resourceId"),
      action: read("action"),
      correlationId: read("correlationId"),
    };
  }, [paramsKey]);

  const direction = useMemo<SortDirection>(() => {
    return new URLSearchParams(paramsKey).get(DIR_PARAM) === SortDirection.ASC
      ? SortDirection.ASC
      : SortDirection.DESC;
  }, [paramsKey]);

  const entry = useMemo<string | null>(() => {
    const value = new URLSearchParams(paramsKey).get(ENTRY_PARAM)?.trim();
    return value || null;
  }, [paramsKey]);

  const commit = useCallback(
    (next: AuditFilter, nextDir: SortDirection, nextEntry: string | null) => {
      const params = new URLSearchParams();
      for (const key of FILTER_KEYS) {
        const value = next[key].trim();
        if (value) params.set(key, value);
      }
      if (nextDir !== SortDirection.DESC) params.set(DIR_PARAM, nextDir);
      if (nextEntry) params.set(ENTRY_PARAM, nextEntry);
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // A filter change drops the open entry: the focused row may leave the page, so the drawer closes
  // rather than stranding a stale detail.
  const setFilter = useCallback(
    (next: AuditFilter) => commit(next, direction, null),
    [commit, direction],
  );
  const patchFilter = useCallback(
    (patch: Partial<AuditFilter>) => commit({ ...filter, ...patch }, direction, null),
    [commit, filter, direction],
  );
  const setDirection = useCallback(
    (next: SortDirection) => commit(filter, next, entry),
    [commit, filter, entry],
  );
  const openEntry = useCallback(
    (id: string) => commit(filter, direction, id),
    [commit, filter, direction],
  );
  const closeEntry = useCallback(
    () => commit(filter, direction, null),
    [commit, filter, direction],
  );
  const reset = useCallback(() => commit(EMPTY_AUDIT_FILTER, direction, null), [commit, direction]);

  return {
    filter,
    direction,
    entry,
    setFilter,
    patchFilter,
    setDirection,
    openEntry,
    closeEntry,
    reset,
  };
}
