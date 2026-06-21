/**
 * `ToastNotifier` is the domain port for the **toast** notification channel.
 *
 * It exposes one method per {@link NotificationLevel} and hides every adapter
 * detail: no `sonner` types leak across this boundary, so the implementation
 * (Sonner today, a custom library-free toaster tomorrow) can be swapped
 * without touching domain or application code. A future `Banner` / `Push`
 * channel gets its own sibling port under `domain/Notification/`.
 */
export interface ToastOptions {
  /** Secondary line rendered under the message. */
  description?: string;
  /** Auto-dismiss duration in milliseconds; the adapter maps it to its unit. */
  durationMs?: number;
  /** Stable id for de-duplication (adapter-defined semantics). */
  id?: string;
}

export interface ToastNotifier {
  success(message: string, options?: ToastOptions): void;
  error(message: string, options?: ToastOptions): void;
  info(message: string, options?: ToastOptions): void;
  warning(message: string, options?: ToastOptions): void;
}
