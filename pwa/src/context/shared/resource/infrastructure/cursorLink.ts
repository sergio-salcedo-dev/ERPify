/**
 * Opaque pagination link encode/decode for the in-memory repository. Mirrors the
 * real backend contract: the CLIENT treats links as opaque transport tokens and
 * never decodes them — only this repository/navigator (the "server") does. The
 * link is a same-origin relative URL carrying a base64 offset so it passes the
 * navigator's same-origin guard exactly like a real API link.
 */
const PATH = "/__mock__/resource";

export function encodeCursorLink(offset: number): string {
  const token = globalThis.btoa(JSON.stringify({ offset }));
  return `${PATH}?cursor=${encodeURIComponent(token)}`;
}

export function decodeCursorOffset(link: string): number {
  const url = new URL(link, "https://mock.invalid");
  const token = url.searchParams.get("cursor");
  if (!token) return 0;
  try {
    const parsed = JSON.parse(globalThis.atob(token)) as { offset?: number };
    return typeof parsed.offset === "number" && parsed.offset >= 0 ? parsed.offset : 0;
  } catch {
    return 0;
  }
}
