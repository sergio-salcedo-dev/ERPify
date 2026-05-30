import { toast } from "sonner";
import type {
  ToastNotifier,
  ToastOptions,
} from "@/context/shared/domain/Notification/Toast/ToastNotifier";

/**
 * Sonner-backed {@link ToastNotifier}. The only file in the app that imports
 * `sonner` for triggering toasts — swapping libraries means replacing this
 * file (and its viewport sibling {@link SonnerToaster}) only.
 */
export class SonnerToastNotifier implements ToastNotifier {
  success(message: string, options?: ToastOptions): void {
    toast.success(message, this.map(options));
  }

  error(message: string, options?: ToastOptions): void {
    toast.error(message, this.map(options));
  }

  info(message: string, options?: ToastOptions): void {
    toast.info(message, this.map(options));
  }

  warning(message: string, options?: ToastOptions): void {
    toast.warning(message, this.map(options));
  }

  private map(
    options?: ToastOptions,
  ): { description?: string; duration?: number; id?: string } | undefined {
    if (!options) return undefined;
    return {
      description: options.description,
      duration: options.durationMs,
      id: options.id,
    };
  }
}
