/**
 * One polite live region for the whole document, created on first use as a direct child of `<body>`.
 *
 * Why one, and why there: a region declared inside each control multiplies with every table row that
 * renders one — N regions that stay silent and compete with the ones that speak — and a region nested
 * in a `<button>` sits among children ARIA treats as presentational, which some browser and screen
 * reader pairs flatten away. A module-level node needs no provider, so no surface can end up rendering
 * outside one and announcing nothing.
 *
 * Why the pause: a region speaks when its text CHANGES, so repeating a message verbatim is silent. The
 * region is emptied at once and written after `SETTLE_MS`, because an accessibility tree is updated per
 * frame rather than per mutation — an empty text replaced within the same frame never reaches it.
 *
 * Why early: a region inserted just before it first speaks is not reliably registered by every browser
 * and screen reader pair, so {@see ensureAnnouncer} lets a control create it when it mounts, well before
 * any click. Why it empties again: the message is stale once its feedback is over, and a reader walking to
 * the end of the document should not meet "Secret copied" long after the fact.
 *
 * Text only: the message is assigned through `textContent`, never parsed as markup.
 */
const REGION_ATTRIBUTE = "data-live-announcer";
const SETTLE_MS = 100;
const CLEAR_AFTER_MS = 5000;

let pendingWrite: ReturnType<typeof setTimeout> | null = null;
let pendingClear: ReturnType<typeof setTimeout> | null = null;

function region(): HTMLElement {
  const existing = document.body.querySelector<HTMLElement>(`[${REGION_ATTRIBUTE}]`);
  if (existing !== null) return existing;

  const node = document.createElement("div");
  node.setAttribute(REGION_ATTRIBUTE, "");
  node.setAttribute("role", "status");
  node.setAttribute("aria-live", "polite");
  node.setAttribute("aria-atomic", "true");
  node.className = "sr-only";
  document.body.appendChild(node);
  return node;
}

/** Create the region ahead of the first announcement. Idempotent, and a no-op outside a browser. */
export function ensureAnnouncer(): void {
  if (typeof document === "undefined") return;
  region();
}

/** Speak `message` politely to assistive technology, even when it repeats the previous one. */
export function announce(message: string): void {
  if (typeof document === "undefined") return;

  if (pendingWrite !== null) clearTimeout(pendingWrite);
  if (pendingClear !== null) clearTimeout(pendingClear);
  region().textContent = "";
  pendingWrite = setTimeout(() => {
    // Looked up again rather than captured: the document may have replaced its body in between.
    region().textContent = message;
    pendingWrite = null;
    pendingClear = setTimeout(() => {
      region().textContent = "";
      pendingClear = null;
    }, CLEAR_AFTER_MS);
  }, SETTLE_MS);
}
