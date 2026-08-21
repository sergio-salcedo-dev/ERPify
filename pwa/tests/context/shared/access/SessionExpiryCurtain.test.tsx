import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { SessionExpiryCurtain } from "@/context/shared/access/infrastructure/ui/SessionExpiryCurtain";
import {
  beginSessionExpiry,
  endSessionExpiry,
} from "@/context/shared/access/application/sessionExpiry";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";

const CHILD = "session-expiry-test__child";

describe("SessionExpiryCurtain", () => {
  beforeEach(() => {
    endSessionExpiry();
  });

  // The claim is reset in beforeEach, never here: Testing Library unmounts in its own
  // afterEach, and releasing the claim while the tree is still mounted updates a component
  // outside act().
  afterEach(() => {
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
    // The bound the user holds, rather than the one they wait out: a navigation that never
    // commits would otherwise leave them on a screen with no controls at all.
    expect(screen.getByTestId("session-expiry__sign-in")).toHaveAttribute("href", "/login");
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

    // This case runs on real timers, so without releasing here the file ends holding the claim,
    // a live navigation budget and a `pagehide` listener — and any test appended after it would
    // inherit them, silently, as an ordering dependency.
    act(() => {
      endSessionExpiry();
    });
  });
});
