import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { SessionExpiryCurtain } from "@/context/shared/access/infrastructure/ui/SessionExpiryCurtain";
import {
  beginSessionExpiry,
  endSessionExpiry,
} from "@/context/shared/access/application/sessionExpiry";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { hardNavigate } from "@/context/shared/navigation/infrastructure/hardNavigate";
import { Routes } from "@/context/shared/routing/domain/Routes";

const { dismissAll } = vi.hoisted(() => ({ dismissAll: vi.fn() }));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { dismissAll, success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

const CHILD = "session-expiry-test__child";

describe("SessionExpiryCurtain", () => {
  beforeEach(() => {
    endSessionExpiry();
    dismissAll.mockClear();
  });

  // sessionExpiry's own claim is reset in beforeEach, never here: Testing Library unmounts in
  // its own afterEach, and releasing the claim while the tree is still mounted updates a
  // component outside act(). hardNavigate's SEPARATE single-flight claim is a different piece
  // of module state that `endSessionExpiry()` never touches, so it needs its own release — a
  // `pagehide`/`persisted: false` dispatch is a no-op when nothing is in flight, and disarms
  // whatever is otherwise, the same way an actual navigation commit would (lighter than
  // hardNavigate.test.ts's module reset for the same reason noted there: this file never holds
  // a claim across a simulated pause).
  afterEach(() => {
    globalThis.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: false }));
    vi.unstubAllGlobals();
  });

  it("is not there until a bounce starts", () => {
    render(
      <SessionExpiryCurtain>
        <p data-testid={CHILD}>Section content</p>
      </SessionExpiryCurtain>,
    );

    expect(screen.getByTestId(CHILD)).toBeInTheDocument();
    expect(screen.queryByTestId("session-expiry__curtain")).toBeNull();
  });

  it("replaces the application once the document is leaving", () => {
    render(
      <SessionExpiryCurtain>
        <p data-testid={CHILD}>Section content</p>
      </SessionExpiryCurtain>,
    );

    act(() => {
      beginSessionExpiry();
    });

    // The point is the ABSENCE: whatever error UI the 401 was about to paint has nowhere to
    // render, on every surface at once, without a single caller learning a new failure shape.
    expect(screen.queryByTestId(CHILD)).toBeNull();
    expect(screen.getByTestId("session-expiry__message")).toBeInTheDocument();
    // Not a bare message in a div. Unmounting the whole tree destroys whatever control raised
    // the 401, so focus falls to <body>; and a live region mounted ALREADY carrying its text is
    // the classic non-announcement — readers register it on insertion and speak later mutations.
    // `role="alert"` is the one role browsers fire on insertion itself.
    expect(screen.getByRole("alert")).toHaveTextContent("Your session expired");
    expect(screen.getByRole("heading", { level: 1 })).toBeInTheDocument();
    expect(screen.getByTestId("session-expiry__curtain")).toHaveFocus();
    // `dismissAll()` only clears what's visible at the instant the bounce starts — a toast
    // raised after that (an unrelated interaction's own failure toast, arriving seconds
    // later) needs a stacking guarantee, not a timing one, to stay from painting on top of
    // a screen whose whole point is to be the only thing visible.
    expect(screen.getByTestId("session-expiry__curtain")).toHaveClass("fixed", "z-[2147483647]");
    // The bound the user holds, rather than the one they wait out: a navigation that never
    // commits would otherwise leave them on a screen with no controls at all.
    expect(screen.getByTestId("session-expiry__sign-in")).toHaveAttribute("href", "/login");
    // The toast viewport is global infrastructure that no longer unmounts with this tree, so
    // a stale toast has to be cleared explicitly instead of hidden by an incidental unmount.
    expect(dismissAll).toHaveBeenCalledTimes(1);
  });

  it("gives the application back when the navigation never committed", () => {
    render(
      <SessionExpiryCurtain>
        <p data-testid={CHILD}>Section content</p>
      </SessionExpiryCurtain>,
    );

    act(() => {
      beginSessionExpiry();
    });
    act(() => {
      endSessionExpiry();
    });

    // Without this direction the curtain is a worse bug than the flash it replaces: an ignored
    // navigation would leave the application blanked, permanently, with no route away.
    expect(screen.getByTestId(CHILD)).toBeInTheDocument();
    expect(screen.queryByTestId("session-expiry__curtain")).toBeNull();
  });

  it("goes up on a real 401 travelling through the transport", async () => {
    // The seam the other cases stub. The curtain is only worth anything if the adapter that
    // detects the expiry and the component that renders it agree on the same fact — and they
    // are three modules apart, with nothing but this test reading them together.
    vi.stubGlobal("location", {
      pathname: "/backoffice/banks",
      search: "",
      replace: vi.fn(),
    });
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(
        JSON.stringify({
          type: "session-expired",
          title: "Session expired.",
          status: HttpStatus.UNAUTHORIZED,
          instance: "01H-instance",
          "correlation-id": "01H-correlation",
        }),
        {
          status: HttpStatus.UNAUTHORIZED,
          headers: new Headers({ "Content-Type": "application/problem+json" }),
        },
      ),
    );

    render(
      <SessionExpiryCurtain>
        <p data-testid={CHILD}>Section content</p>
      </SessionExpiryCurtain>,
    );

    let raised: unknown;
    await act(async () => {
      raised = await new FetchHttpClient()
        .get("/api/v1/backoffice/banks")
        .then(() => undefined)
        .catch((error: unknown) => error);
    });

    // The 401 still throws, unchanged: the HTTP contract is what this fix deliberately did NOT
    // touch — the error simply has nowhere left to render.
    expect(raised).toMatchObject({ problem: { status: HttpStatus.UNAUTHORIZED } });
    expect(screen.queryByTestId(CHILD)).toBeNull();
    expect(screen.getByTestId("session-expiry__curtain")).toBeInTheDocument();
  });

  it("never paints when superseded — the claim begins and ends in the same synchronous tick", () => {
    // Something else (sign-out, in the real race this closes) already owns hardNavigate's one
    // sanctioned sink when the session-expiry bounce is attempted.
    vi.stubGlobal("location", { pathname: "/backoffice/banks", search: "", replace: vi.fn() });
    hardNavigate(Routes.HOME, vi.fn());

    render(
      <SessionExpiryCurtain>
        <p data-testid={CHILD}>Section content</p>
      </SessionExpiryCurtain>,
    );

    act(() => {
      beginSessionExpiry();
      // Refused as "superseded": nothing raises, nothing unloads, and `endSessionExpiry` (the
      // real onFailure this call site uses in production) runs synchronously, in the same tick.
      hardNavigate(`${Routes.LOGIN}?reason=session-expired`, endSessionExpiry);
    });

    // React never renders the intermediate `true` a plain `beginSessionExpiry()` alone would
    // have produced: the curtain — and the toast-clearing it would otherwise trigger — never
    // appears, because by the time this effect could run, `expiring` is already back to false.
    expect(screen.getByTestId(CHILD)).toBeInTheDocument();
    expect(screen.queryByTestId("session-expiry__curtain")).toBeNull();
    expect(dismissAll).not.toHaveBeenCalled();
  });
});
