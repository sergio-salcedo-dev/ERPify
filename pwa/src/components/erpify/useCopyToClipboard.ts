"use client";

import { useEffect, useRef, useState } from "react";
import { flushSync } from "react-dom";

export type CopyStatus = "idle" | "copied" | "error";

export interface UseCopyToClipboardOptions {
  /** How long the copied/error feedback remains, in ms. Defaults to 2000. */
  feedbackTimeoutMs?: number;
  /** Called after the clipboard write settles (success or failure). */
  onCopyResult?: (status: CopyStatus) => void;
}

const DEFAULT_FEEDBACK_MS = 2000;

/**
 * The async Clipboard API is the only path. It needs a secure context, which every surface of this app is —
 * production and staging are served over HTTPS and `localhost` counts as secure — so a missing API means an
 * insecure origin such as plain HTTP on a LAN address, and the copy reports a failure there instead of
 * reaching for the deprecated `document.execCommand("copy")`.
 */
async function writeToClipboard(value: string): Promise<void> {
  if (typeof navigator === "undefined" || !navigator.clipboard?.writeText) {
    throw new TypeError("Clipboard API unavailable.");
  }
  await navigator.clipboard.writeText(value);
}

/**
 * The one place the app writes to the clipboard. Controls that copy differ in how they look — a labelled
 * button, a code token — and share this: the write, the copied/error feedback, and its expiry. Keeping the
 * write here is what lets a single component decide how an unavailable clipboard is reported.
 */
export function useCopyToClipboard(
  value: string,
  { feedbackTimeoutMs = DEFAULT_FEEDBACK_MS, onCopyResult }: UseCopyToClipboardOptions = {},
): { status: CopyStatus; copy: () => Promise<void> } {
  const [status, setStatus] = useState<CopyStatus>("idle");
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const mountedRef = useRef(true);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
    };
  }, []);

  async function copy(): Promise<void> {
    // A live region speaks only when its text changes, so a second identical outcome would be
    // silent. Clearing it before the write lands makes every outcome a change; flushed so the empty
    // state reaches the DOM on its own instead of merging into the render that sets the result.
    if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
    flushSync(() => setStatus("idle"));
    let next: CopyStatus;
    try {
      await writeToClipboard(value);
      next = "copied";
    } catch {
      next = "error";
    }
    // A control unmounted while the write was in flight has no feedback left to show and no cleanup
    // left to run, so arming a timer here would leave one nothing ever clears.
    if (!mountedRef.current) return;
    setStatus(next);
    // Armed before the caller is told, so a callback that throws cannot strand the control in its
    // copied/error state.
    if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
    timeoutRef.current = setTimeout(() => setStatus("idle"), feedbackTimeoutMs);
    onCopyResult?.(next);
  }

  return { status, copy };
}
