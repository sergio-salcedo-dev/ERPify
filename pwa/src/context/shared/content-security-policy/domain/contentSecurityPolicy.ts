/**
 * The document Content-Security-Policy, built per request.
 *
 * `script-src` carries a per-request nonce plus `'strict-dynamic'` and never
 * `'unsafe-inline'`: Next stamps the nonce onto every framework, bundle and
 * hydration script it renders (it parses it out of the request's own
 * `Content-Security-Policy` header), and `'strict-dynamic'` lets those trusted
 * scripts load their chunks. `'self'` stays as the fallback for browsers that
 * predate `'strict-dynamic'` — a browser that understands it ignores the
 * allow-list sources, so `'self'` widens nothing for them. `style-src` keeps
 * `'unsafe-inline'`: React `style` attributes cannot carry a nonce.
 *
 * `'unsafe-eval'` exists only for development (React/Turbopack debugging).
 */
export interface ContentSecurityPolicyOptions {
  /** Per-request nonce (base64, unguessable). */
  readonly nonce: string;
  readonly isProduction: boolean;
  /** Origin of the Symfony API when it is not same-origin; `""` otherwise. */
  readonly apiOrigin: string;
}

/** 128 bits of CSPRNG output, base64-encoded (alphabet is valid inside a CSP nonce). */
export function mintNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return btoa(String.fromCharCode(...bytes));
}

/** Reduces a configured API base URL to its origin; `""` when absent or unparsable. */
export function apiOriginFrom(raw: string | undefined): string {
  const trimmed = raw?.trim();
  if (!trimmed) return "";
  try {
    const url = new URL(trimmed);
    return `${url.protocol}//${url.host}`;
  } catch {
    return "";
  }
}

export function buildContentSecurityPolicy(options: ContentSecurityPolicyOptions): string {
  const { nonce, isProduction, apiOrigin } = options;
  return [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${isProduction ? "" : " 'unsafe-eval'"}`,
    "style-src 'self' 'unsafe-inline'",
    // Only the origins the app renders images from: itself and the API origin
    // (every `<img>` goes through `imageReadUrl`). A blanket `https:` would be an
    // exfiltration channel for an injected script (`new Image().src = …`).
    `img-src 'self' data: blob: ${apiOrigin}`.trim(),
    "font-src 'self' data:",
    `connect-src 'self' ${apiOrigin}`.trim(),
    "media-src 'self' data: blob:",
    "worker-src 'self' blob:",
    "manifest-src 'self'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "base-uri 'self'",
    "upgrade-insecure-requests",
  ].join("; ");
}
