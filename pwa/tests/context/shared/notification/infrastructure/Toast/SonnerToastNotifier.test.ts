import { beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "sonner";
import { SonnerToastNotifier } from "@/context/shared/notification/infrastructure/Toast/SonnerToastNotifier";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";

vi.mock("sonner", () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    dismiss: vi.fn(),
  },
}));

describe("SonnerToastNotifier", () => {
  const notifier = new SonnerToastNotifier();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("forwards success to toast.success with undefined options", () => {
    notifier.success("Saved");
    expect(toast.success).toHaveBeenCalledWith("Saved", undefined);
  });

  it("forwards info and warning to the matching sonner method", () => {
    notifier.info("Heads up");
    notifier.warning("Careful");
    expect(toast.info).toHaveBeenCalledWith("Heads up", undefined);
    expect(toast.warning).toHaveBeenCalledWith("Careful", undefined);
  });

  it("maps ToastOptions to the sonner option shape", () => {
    notifier.error("Boom", { description: "details", durationMs: 5000, id: "x" });
    expect(toast.error).toHaveBeenCalledWith("Boom", {
      description: "details",
      duration: 5000,
      id: "x",
    });
  });

  it("forwards dismissAll to toast.dismiss with no id (dismiss every toast)", () => {
    notifier.dismissAll();
    expect(toast.dismiss).toHaveBeenCalledWith();
  });
});

describe("toastNotifier singleton", () => {
  it("exposes the full ToastNotifier surface", () => {
    expect(typeof toastNotifier.success).toBe("function");
    expect(typeof toastNotifier.error).toBe("function");
    expect(typeof toastNotifier.info).toBe("function");
    expect(typeof toastNotifier.warning).toBe("function");
    expect(typeof toastNotifier.dismissAll).toBe("function");
  });
});
