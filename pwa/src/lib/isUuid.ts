/**
 * True when `value` is a canonical RFC 4122 UUID string (versions 1–8, correct
 * variant). Use it to validate ids taken from untrusted surfaces — route params,
 * query strings, API payloads — before they flow into URLs, Mercure topic IRIs,
 * or requests. Defense in depth on the client; never a substitute for the API's
 * own `#[Assert\Uuid]` validation.
 */
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function isUuid(value: string): boolean {
  return UUID_RE.test(value);
}
