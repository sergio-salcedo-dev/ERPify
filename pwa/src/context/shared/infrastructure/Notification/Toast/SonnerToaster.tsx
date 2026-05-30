"use client";

import { Toaster, type ToasterProps } from "sonner";

/**
 * Sonner viewport mounted once in the root layout. The render half of the
 * Sonner adapter — co-located with {@link SonnerToastNotifier} so the whole
 * Sonner implementation lives together. Defaults can be overridden via props.
 *
 * - `position="bottom-right"` — app-wide placement.
 * - `richColors` — Sonner's built-in tonal styling per level (success/error/info/warning).
 * - `closeButton` — Sonner renders it with an accessible name ("Close toast").
 */
export function SonnerToaster(props: Readonly<ToasterProps>) {
  return <Toaster position="bottom-right" richColors closeButton {...props} />;
}
