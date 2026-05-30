import type { ToastNotifier } from "@/context/shared/domain/Notification/Toast/ToastNotifier";
import { SonnerToastNotifier } from "./SonnerToastNotifier";

/**
 * Default {@link ToastNotifier} for the application. Consumers MUST type the
 * import as the `ToastNotifier` port, never as the concrete adapter, so the
 * implementation can be swapped without churn (mirrors `dateTimeProvider`).
 */
export const toastNotifier: ToastNotifier = new SonnerToastNotifier();

export type {
  ToastNotifier,
  ToastOptions,
} from "@/context/shared/domain/Notification/Toast/ToastNotifier";
