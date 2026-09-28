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
 * Text only: the message is assigned through `textContent`, never parsed as markup.
 */
const REGION_ATTRIBUTE = "data-live-announcer";
const SETTLE_MS = 100;

let pendingWrite: ReturnType<typeof setTimeout> | null = null;

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

/** Speak `message` politely to assistive technology, even when it repeats the previous one. */
export function announce(message: string): void {
  if (typeof document === "undefined") return;

  const node = region();
  if (pendingWrite !== null) clearTimeout(pendingWrite);
  node.textContent = "";
  pendingWrite = setTimeout(() => {
    node.textContent = message;
    pendingWrite = null;
  }, SETTLE_MS);
}
