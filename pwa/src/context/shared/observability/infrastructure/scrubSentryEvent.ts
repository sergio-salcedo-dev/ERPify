import type { Event } from "@sentry/nextjs";
import {
  isDenylistedKey,
  isIdentityAxisKey,
  REDACTION_SENTINEL,
  scrubDeep,
} from "@/context/shared/observability/domain/redaction";

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
 * filtering and are parsed param-by-param — where the identity axes are
 * redacted alongside the denylist, since a URL is where those travel).
 * Free-text (`message`, the captured
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
    request.headers = redactRefererIn(scrubDeep(headers) as Record<string, string>);
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

/**
 * `Referer` is the one header whose value IS a URI, so the key-based scrub above cannot help it. Left alone
 * it carries the referring document's whole URL — for anything fired from the audit screen, the person ids
 * that screen holds — into a third-party sink with retention of its own.
 */
function redactRefererIn(headers: Record<string, string>): Record<string, string> {
  const redacted: Record<string, string> = {};
  for (const [name, value] of Object.entries(headers)) {
    const isReferer: boolean = name.toLowerCase() === "referer";

    redacted[name] = isReferer && typeof value === "string" ? scrubUrl(value) : value;
  }
  return redacted;
}

/** Attempt to scrub stringified JSON bodies which would otherwise bypass redaction. */
function tryScrubJson(data: string): string {
  try {
    const parsed = JSON.parse(data);
    return JSON.stringify(scrubDeep(parsed));
  } catch {
    // Fall back to original if not valid JSON; we do not log here to avoid
    // noise on every non-JSON POST body (e.g. form-data).
    return data;
  }
}

/**
 * Cleans a raw `a=b&c=d` query string. Both families of sensitive value — the denylisted secrets and the
 * identity axes — keep their key and lose their value to the sentinel, which is the rule for a URI and
 * not the strip semantics the recursive filter applies to structured keys: a URL's diagnostic worth is
 * its shape, and a reader has to be able to tell a request that carried a token from one that did not.
 * The API writes the same token over the same axes in the access log and in the per-error log line, so
 * an event lines up with them.
 *
 * Sentry is a third-party sink with retention of its own that no erasure path reaches, so a person id
 * that arrives here outlives the erasure the application confirmed to the subject.
 */
function scrubQueryString(queryString: string): string {
  return scrubPairs(queryString, true);
}

/**
 * Every rule above matches a parameter NAME, and that misses a whole class: when a session expires the
 * client navigates to `/login?next=<the entire audit URL>`, so the ids that screen holds arrive under a
 * name no denylist will ever contain. A value that is itself a URI is followed one level — enough for the
 * shape that exists, and no recursion to bound.
 */
function scrubNestedUri(value: string): string {
  const queryStart = value.indexOf("?");
  if (queryStart === -1) {
    return value;
  }

  return `${value.slice(0, queryStart + 1)}${scrubPairs(value.slice(queryStart + 1), false)}`;
}

function scrubPairs(query: string, followNested: boolean): string {
  const params = new URLSearchParams(query);
  const scrubbed = new URLSearchParams();
  for (const [key, value] of params) {
    if (isDenylistedKey(key) || isIdentityAxisKey(key)) {
      scrubbed.append(key, REDACTION_SENTINEL);
      continue;
    }
    scrubbed.append(key, followNested ? scrubNestedUri(value) : value);
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
