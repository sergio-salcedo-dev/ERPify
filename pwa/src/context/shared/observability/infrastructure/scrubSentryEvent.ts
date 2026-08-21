import type { Event } from "@sentry/nextjs";
import {
  isDenylistedKey,
  decodeUntilQuerySurfaces,
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
 * redacted alongside the denylist, since a URL is where those travel). A
 * breadcrumb's URL-shaped values get that same treatment, for the same reason
 * and by the same reading: the SDK writes one breadcrumb per `fetch` and per
 * history entry, and each carries the whole request URL as a STRING.
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
    event.breadcrumbs = scrubBreadcrumbs(event.breadcrumbs) as E["breadcrumbs"];
  }

  if (event.request) {
    scrubRequest(event.request);
  }

  return event;
}

/** One entry of `event.breadcrumbs`, named off the event type so no `Breadcrumb` import is needed. */
type SentryBreadcrumb = NonNullable<Event["breadcrumbs"]>[number];

/**
 * How deep a breadcrumb's `data` is walked for URL-shaped values. Breadcrumb data is flat in
 * every SDK-authored crumb; the bound exists for a hand-authored one and is deliberately far
 * below {@link scrubDeep}'s, since nothing here needs to reach an arbitrary payload.
 */
const MAX_BREADCRUMB_DATA_DEPTH = 4;

/**
 * A value the SDK wrote as a URL: absolute, protocol-relative, or root-relative. Matched by SHAPE
 * rather than by the key it sits under, because a key list is exactly what failed for this class
 * twice already — in the access log (#389/#803) and here. The SDK's own crumbs put the URL under
 * `data.url` for fetch/xhr and `data.from`/`data.to` for a history entry, but a hand-authored crumb
 * names it whatever it likes and the rule must not depend on having guessed that name.
 *
 * The `?` is what makes the rewrite safe on free text: a string with no query is returned by
 * {@link scrubUrl} unchanged anyway, so requiring one narrows the walk to values that actually
 * carry parameters. A URL embedded MID-string (a console message quoting one) is not seen — the
 * same free-text scope the rest of this module keeps, recorded as a residual rather than closed.
 */
function isUrlLikeValue(value: string): boolean {
  return /^(?:https?:\/\/|\/)/i.test(value) && value.includes("?");
}

/**
 * Sentry adds an automatic breadcrumb per `fetch`, and that breadcrumb carries the full request
 * URL — query string included. Key-based scrubbing does not look inside a URL string, so a search
 * whose filter names a person rode into Sentry in clear under a key (`url`) that no denylist would
 * ever hold. Sentry is a third-party sink with retention of its own that no erasure path reaches.
 *
 * The denylist pass runs first and the URL pass second, over what it left: a `data` key the
 * denylist strips has no URL to scrub afterwards, and the reverse order would scrub a value that
 * was about to be dropped.
 */
function scrubBreadcrumbs(breadcrumbs: SentryBreadcrumb[]): SentryBreadcrumb[] {
  const denylisted = scrubDeep(breadcrumbs) as SentryBreadcrumb[];

  return denylisted.map((crumb: SentryBreadcrumb) => {
    if (!crumb || typeof crumb !== "object" || !crumb.data) {
      return crumb;
    }
    return { ...crumb, data: scrubUrlLikeValues(crumb.data, 0) as SentryBreadcrumb["data"] };
  });
}

/** Rewrites every URL-shaped string inside a breadcrumb's `data`, to {@link MAX_BREADCRUMB_DATA_DEPTH}. */
function scrubUrlLikeValues(value: unknown, depth: number): unknown {
  if (typeof value === "string") {
    return isUrlLikeValue(value) ? scrubUrl(value) : value;
  }

  if (depth >= MAX_BREADCRUMB_DATA_DEPTH || value === null || typeof value !== "object") {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item: unknown) => scrubUrlLikeValues(item, depth + 1));
  }

  // Same reading as scrubDeep: a non-plain object (Date, Map, a class instance) is passed through
  // rather than rebuilt, so nothing here can break an instance the SDK attached.
  const ctor = (value as Record<string, unknown>).constructor;
  if (ctor && ctor !== Object) {
    return value;
  }

  const scrubbed: Record<string, unknown> = {};
  for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
    scrubbed[key] = scrubUrlLikeValues(nested, depth + 1);
  }
  return scrubbed;
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
  return scrubPairs(queryString, 0);
}

/**
 * How far a URI carried inside a value is followed. Mirrors the API's
 * `RequestUriRedaction::MAX_NESTED_URI_DEPTH`: one level reaches `?next=<the whole audit URL>`, and the
 * second reaches the same URL wrapped once more — the shape a client produces when an expired session
 * bounces through the login redirect twice. Stopping a level short of the API is not a smaller guarantee,
 * it is the same identifier kept out of one sink and let into the other.
 */
const MAX_NESTED_URI_DEPTH = 2;

/**
 * Every rule above matches a parameter NAME, and that misses a whole class: when a session expires the
 * client navigates to `/login?next=<the entire audit URL>`, so the ids that screen holds arrive under a
 * name no denylist will ever contain. A value that is itself a URI is therefore followed and scrubbed by
 * the same vocabulary, to the bound above.
 */
function scrubNestedUri(value: string, depth: number): string {
  const decoded = decodeUntilQuerySurfaces(value);
  const queryStart = decoded.indexOf("?");
  if (queryStart === -1) {
    return value;
  }

  const scrubbed = `${decoded.slice(0, queryStart + 1)}${scrubPairs(decoded.slice(queryStart + 1), depth)}`;

  // Only a value that WAS carrying an identifier is rewritten. Returning the caller's bytes otherwise keeps
  // the encoding depth an operator reads the request by, which decoding here would otherwise flatten.
  return scrubbed === decoded ? value : scrubbed;
}

function scrubPairs(query: string, depth: number): string {
  const params = new URLSearchParams(query);
  const scrubbed = new URLSearchParams();
  for (const [key, value] of params) {
    if (isDenylistedKey(key) || isIdentityAxisKey(key)) {
      scrubbed.append(key, REDACTION_SENTINEL);
      continue;
    }
    const follows = depth < MAX_NESTED_URI_DEPTH;
    scrubbed.append(key, follows ? scrubNestedUri(value, depth + 1) : value);
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
