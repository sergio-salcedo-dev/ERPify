"use client";

import { useEffect, type RefObject } from "react";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";

/**
 * Documented DataTable keyboard contract: `/` focuses the list search. The
 * listener is document-level so it works wherever focus rests on the page, and
 * inert while the user is typing in a field or interacting with a transient
 * layer (dialog/menu) — so a slash typed into an input is never hijacked.
 */
export function useSlashFocus(ref: RefObject<HTMLInputElement | null>): void {
  useEffect(() => {
    const handleSlash = (event: globalThis.KeyboardEvent): void => {
      if (event.key !== KeyboardKey.SLASH || event.defaultPrevented) return;
      const target = event.target;
      if (
        target instanceof HTMLElement &&
        target.closest(
          "input, textarea, select, [contenteditable='true'], [role='dialog'], [role='alertdialog'], [role='menu']",
        )
      ) {
        return;
      }
      event.preventDefault();
      ref.current?.focus();
    };
    document.addEventListener("keydown", handleSlash);
    return () => document.removeEventListener("keydown", handleSlash);
  }, [ref]);
}
