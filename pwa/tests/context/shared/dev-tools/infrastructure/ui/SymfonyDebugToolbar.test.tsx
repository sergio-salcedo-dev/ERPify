import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SymfonyDebugToolbar } from "@/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("SymfonyDebugToolbar", () => {
  it("renders nothing until a token is published", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { container } = render(<SymfonyDebugToolbar observer={observer} />);
    expect(container.querySelector("[data-testid='dev-tools__symfony-toolbar']")).toBeNull();
  });

  it("fetches /_dev/wdt-loader/{token} and mounts the fragment when a token arrives", async () => {
    const observer = new EventTargetDebugTokenObserver();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response("<div id='sfwdt-marker'>toolbar</div>", {
        status: 200,
        headers: { "Content-Type": "text/html" },
      }),
    );

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "ddd", profilerUrl: "/_profiler/ddd" });

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalledWith(
        "/_dev/wdt-loader/ddd",
        expect.objectContaining({ cache: "no-store" }),
      );
    });
    await waitFor(() => {
      expect(
        screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker"),
      ).not.toBeNull();
    });
  });

  it("renders nothing and does not throw when the fragment fetch fails", async () => {
    const observer = new EventTargetDebugTokenObserver();
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("network"));

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "eee", profilerUrl: null });

    await waitFor(() => {
      const host = screen.getByTestId("dev-tools__symfony-toolbar");
      expect(host.childElementCount).toBe(0);
    });
  });
});
