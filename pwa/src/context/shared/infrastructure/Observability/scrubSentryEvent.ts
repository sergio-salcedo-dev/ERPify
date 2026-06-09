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
  const { data, headers, cookies, query_string: qs, url } = request;

  if (data) {
    if (typeof data === "object") {
      request.data = scrubDeep(data);
    } else if (typeof data === "string" && data.startsWith("{")) {
      request.data = tryScrubJson(data);
    }
  }

  if (headers) {
    request.headers = scrubDeep(headers) as Record<string, string>;
  }

  if (cookies) {
    request.cookies = scrubDeep(cookies) as Record<string, string>;
  }

  if (typeof qs === "string" && qs !== "") {
    request.query_string = scrubQueryString(qs);
  }

  if (typeof url === "string") {
    request.url = scrubUrl(url);
  }
}

/** Attempt to scrub stringified JSON bodies which would otherwise bypass redaction. */
function tryScrubJson(data: string): string {
  try {
    const parsed = JSON.parse(data);
    return JSON.stringify(scrubDeep(parsed));
  } catch (error) {
    // Fall back to original if not valid JSON; we do not log here to avoid
    // noise on every non-JSON POST body (e.g. form-data).
    return data;
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
  const hashStart = url.indexOf("#");
  const pathAndQuery = hashStart === -1 ? url : url.slice(0, hashStart);
  const hash = hashStart === -1 ? "" : url.slice(hashStart);

  const queryStart = pathAndQuery.indexOf("?");
  if (queryStart === -1) {
    return pathAndQuery + hash;
  }

  const path = pathAndQuery.slice(0, queryStart);
  const query = pathAndQuery.slice(queryStart + 1);

  const scrubbedQuery = scrubQueryString(query);
  const result = scrubbedQuery === "" ? path : `${path}?${scrubbedQuery}`;

  return result + hash;
}
