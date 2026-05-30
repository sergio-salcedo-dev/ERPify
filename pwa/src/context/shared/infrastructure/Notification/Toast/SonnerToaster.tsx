"use client";

import { Toaster, type ToasterProps } from "sonner";

/**
 * Upper bound on how many toasts stay visible at once before older ones are
 * dropped from the pile. This is a UX tuning value, not a deployment concern —
 * it never varies per environment, so it lives as a named constant here rather
 * than as an env var (a `NEXT_PUBLIC_*` var would be bundled into the client,
 * add indirection + docs burden, and still resolve to the same number
 * everywhere). Sized to cover a realistic burst of *individual* deletions done
 * in quick succession without walling off the viewport. It is NOT meant to
 * scale with batch size: a future bulk action (e.g. "delete selected") should
 * emit a single aggregate toast ("12 banks deleted") via a shared `id`, not one
 * toast per record — so this cap stays small on purpose.
 */
const MAX_VISIBLE_TOASTS = 6;

/**
 * Sonner viewport mounted once in the root layout. The render half of the
 * Sonner adapter — co-located with {@link SonnerToastNotifier} so the whole
 * Sonner implementation lives together. Defaults can be overridden via props.
 *
 * - `position="top-center"` — app-wide placement. Top anchor means Sonner
 *   stacks the newest toast on top, so rapid bursts (e.g. deleting several
 *   banks in a row) pile up newest-first instead of burying it.
 * - `expand` — keep the stack always expanded so concurrent toasts sit as a
 *   readable column instead of overlapping behind the front one.
 * - `visibleToasts={MAX_VISIBLE_TOASTS}` — allow a taller pile than Sonner's
 *   default of 3 so a burst of deletions stays legible.
 * - `richColors` — Sonner's built-in tonal styling per level (success/error/info/warning).
 * - `closeButton` — Sonner renders it with an accessible name ("Close toast").
 */
export function SonnerToaster(props: Readonly<ToasterProps>) {
  return (
    <Toaster
      position="top-center"
      expand
      visibleToasts={MAX_VISIBLE_TOASTS}
      richColors
      closeButton
      {...props}
    />
  );
}
