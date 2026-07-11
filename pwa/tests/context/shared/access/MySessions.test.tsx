import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";

// The hook resolves its repository from the DI container; mock that boundary so
// the component drives a controllable fake SessionsRepository (no network).
const list = vi.fn();
const revokeOthers = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      list: (...args: unknown[]) => list(...args),
      revokeOthers: (...args: unknown[]) => revokeOthers(...args),
    }),
  },
}));

import { MySessions } from "@/context/shared/access/infrastructure/ui/MySessions";
import type { SessionSummary } from "@/context/shared/access/domain/SessionSummary";

const CURRENT: SessionSummary = {
  id: "s-1",
  device: "Chrome on macOS",
  createdAt: "2026-07-10T09:00:00.000Z",
  current: true,
};
const OTHER: SessionSummary = {
  id: "s-2",
  device: "Safari on iPhone",
  createdAt: "2026-07-09T18:30:00.000Z",
  current: false,
};

beforeEach(() => {
  list.mockReset();
  revokeOthers.mockReset();
});

describe("<MySessions>", () => {
  it("lists active sessions and labels the current one 'This device' textually", async () => {
    list.mockResolvedValue([CURRENT, OTHER]);

    render(<MySessions />);

    expect(await screen.findByTestId("my-sessions__row-s-1")).toBeInTheDocument();
    expect(screen.getByTestId("my-sessions__row-s-2")).toBeInTheDocument();
    expect(screen.getByText("Chrome on macOS")).toBeInTheDocument();
    // A textual label, not colour alone.
    expect(screen.getByText("This device")).toBeInTheDocument();
    // Only the current row carries the badge; the other has no per-row close.
    expect(screen.getByTestId("my-sessions__current-s-1")).toBeInTheDocument();
    expect(screen.queryByTestId("my-sessions__current-s-2")).not.toBeInTheDocument();
  });

  it("signs out other devices, then refreshes the list", async () => {
    list.mockResolvedValueOnce([CURRENT, OTHER]).mockResolvedValueOnce([CURRENT]);
    revokeOthers.mockResolvedValue(undefined);

    render(<MySessions />);

    await screen.findByTestId("my-sessions__row-s-2");
    fireEvent.click(screen.getByTestId("my-sessions__revoke-others"));

    await waitFor(() => expect(revokeOthers).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(screen.queryByTestId("my-sessions__row-s-2")).not.toBeInTheDocument(),
    );
    expect(screen.getByTestId("my-sessions__row-s-1")).toBeInTheDocument();
  });

  it("disables the revoke action when the current session is the only one", async () => {
    list.mockResolvedValue([CURRENT]);

    render(<MySessions />);

    await screen.findByTestId("my-sessions__row-s-1");
    expect(screen.getByTestId("my-sessions__revoke-others")).toBeDisabled();
    expect(revokeOthers).not.toHaveBeenCalled();
  });
});
