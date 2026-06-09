import type { Event } from "@sentry/nextjs";
import { isDenylistedKey, scrubDeep } from "@/context/shared/domain/Observability/redaction";

/**
 * Sentry `beforeSend` / `beforeSendTransaction` hook: defense-in-depth scrub of
 * denylisted keys from every event (errors AND performance transactions) before
 * it leaves the process, recursively, mirroring the API's `SentryEventScrubber`.
 * Pairs with `sendDefaultPii: false` (the belt to those braces) and reuses the
 * shared {@link scrubDeep} denylist so the browser/Next scrub stays in parity
 * with the back-end's RFC 9457 + Sentry redaction.
 *
 * Scrubs `extra`, `contexts`, `user`, `breadcrumbs`, and the caller-controlled
 * `request` sub-objects (`data` / `headers` / `cookies`), plus the raw
 * `query_string` and the `url`'s query (both strings, so they bypass key-based
 * filtering and are parsed param-by-param). Free-text (`message`, the captured
 * `Error.message`/stack) is intentionally NOT key-scrubbed — same scope as the
 * API scrubber; `sendDefaultPii: false` already keeps headers/cookies/bodies off
 * spans by default.
 */
export function scrubSentryEvent<E extends Event>(event: E): E {
  if (event.extra) {
    event.extra = scrubDeep(event.extra) as E["extra"];
  }
  if (event.contexts) {
    event.contexts = scrubDeep(event.contexts) as E["contexts"];
  }
  if (event.user) {
    event.user = scrubDeep(event.user) as E["user"];
  }
  if (event.breadcrumbs) {
    event.breadcrumbs = scrubDeep(event.breadcrumbs) as E["breadcrumbs"];
  }

  if (event.request) {
    scrubRequest(event.request);
  }

  return event;
}

/**
 * Scrubs the caller-controlled `request` sub-objects in place: the structured
 * `data` / `headers` / `cookies` via the recursive denylist, and the raw
 * `query_string` + `url` query (strings that bypass key-based filtering, so they
 * are parsed param-by-param).
 */
function scrubRequest(request: NonNullable<Event["request"]>): void {
  if (request.data) {
    if (typeof request.data === "object") {
      request.data = scrubDeep(request.data);
    } else if (typeof request.data === "string" && request.data.startsWith("{")) {
      // Attempt to scrub stringified JSON bodies which would otherwise bypass redaction.
      try {
        const parsed = JSON.parse(request.data);
        request.data = JSON.stringify(scrubDeep(parsed));
      } catch {
        // Fall back to original if not valid JSON.
      }
    }
  }
  if (request.headers) {
    request.headers = scrubDeep(request.headers) as Record<string, string>;
  }
  if (request.cookies) {
    request.cookies = scrubDeep(request.cookies) as Record<string, string>;
  }
  if (typeof request.query_string === "string" && request.query_string !== "") {
    request.query_string = scrubQueryString(request.query_string);
  }
  if (typeof request.url === "string") {
    request.url = scrubUrl(request.url);
  }
}

/** Strips denylisted params from a raw `a=b&c=d` query string. */
function scrubQueryString(queryString: string): string {
  const params = new URLSearchParams(queryString);
  const scrubbed = new URLSearchParams();
  for (const [key, value] of params) {
    if (!isDenylistedKey(key)) {
      scrubbed.append(key, value);
    }
  }
  return scrubbed.toString();
}

/** Strips denylisted params from a URL's query, preserving the path and hash. */
function scrubUrl(url: string): string {
  const [pathAndQuery, hash] = url.split("#");
  const [path, query] = pathAndQuery.split("?");

  if (query === undefined) {
    return hash !== undefined ? `${path}#${hash}` : path;
  }

  const scrubbedQuery = scrubQueryString(query);
  const result = scrubbedQuery === "" ? path : `${path}?${scrubbedQuery}`;

  return hash !== undefined ? `${result}#${hash}` : result;
}
