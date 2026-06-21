import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SymfonyDebugToolbar } from "@/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar";
import { EventTargetDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/EventTargetDebugTokenObserver";

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

  it("loads the toolbar once and ignores later tokens, never re-wiping the mounted DOM", async () => {
    const observer = new EventTargetDebugTokenObserver();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response("<div id='sfwdt-marker'>toolbar</div>", {
        status: 200,
        headers: { "Content-Type": "text/html" },
      }),
    );

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "first", profilerUrl: "/_profiler/first" });

    let mounted: Element | null = null;
    await waitFor(() => {
      mounted = screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker");
      expect(mounted).not.toBeNull();
    });
    expect(fetchSpy).toHaveBeenCalledTimes(1);
    expect(fetchSpy).toHaveBeenCalledWith(
      "/_dev/wdt-loader/first",
      expect.objectContaining({ cache: "no-store" }),
    );

    // A later request publishes a fresh token; Symfony's own AJAX panel tracks
    // it, so the host must not re-fetch or re-wipe. Asserting node identity (not
    // just non-null) is what proves "no re-wipe" — a re-mount with the same HTML
    // would replace the node and still be non-null, yet that is exactly the
    // detached-DOM churn that crashed the toolbar's global AJAX handlers.
    observer.publish({ token: "second", profilerUrl: "/_profiler/second" });
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker")).toBe(
      mounted,
    );
  });

  it("does not re-wipe the toolbar when an earlier in-flight load resolves after a later one", async () => {
    const observer = new EventTargetDebugTokenObserver();
    const resolvers: Array<(html: string) => void> = [];
    vi.spyOn(globalThis, "fetch").mockImplementation(
      () =>
        new Promise<Response>((resolve) => {
          resolvers.push((html) =>
            resolve(new Response(html, { status: 200, headers: { "Content-Type": "text/html" } })),
          );
        }),
    );

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "first", profilerUrl: "/_profiler/first" });
    await waitFor(() => expect(resolvers).toHaveLength(1));

    // A second token arrives while the first load is still in flight.
    observer.publish({ token: "second", profilerUrl: "/_profiler/second" });
    await waitFor(() => expect(resolvers).toHaveLength(2));

    // The later token's load resolves first and mounts the toolbar.
    resolvers[1]("<div id='sfwdt-marker'>second</div>");
    let mounted: Element | null = null;
    await waitFor(() => {
      mounted = screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker");
      expect(mounted?.textContent).toBe("second");
    });

    // The earlier, now-superseded load resolves late: it must not wipe/replace
    // the mounted node — that detached-DOM write is the crash this fix prevents.
    resolvers[0]("<div id='sfwdt-marker'>first</div>");
    await new Promise((resolve) => setTimeout(resolve, 0));

    const after = screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker");
    expect(after).toBe(mounted);
    expect(after?.textContent).toBe("second");
  });

  it("retries on a later token after an initial load failure", async () => {
    const observer = new EventTargetDebugTokenObserver();
    const fetchSpy = vi
      .spyOn(globalThis, "fetch")
      .mockRejectedValueOnce(new Error("network"))
      .mockResolvedValueOnce(
        new Response("<div id='sfwdt-marker'>ok</div>", {
          status: 200,
          headers: { "Content-Type": "text/html" },
        }),
      );

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "first", profilerUrl: "/_profiler/first" });
    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));
    // First load failed: nothing mounted and the latch stayed unset.
    expect(
      screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker"),
    ).toBeNull();

    // A later request publishes a fresh token → the toolbar retries and mounts.
    observer.publish({ token: "second", profilerUrl: "/_profiler/second" });
    await waitFor(() => {
      expect(
        screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker"),
      ).not.toBeNull();
    });
    expect(fetchSpy).toHaveBeenCalledTimes(2);
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
