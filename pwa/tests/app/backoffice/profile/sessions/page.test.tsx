import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";

// useMySessions resolves its repository from the DI container; mock that boundary so the page renders
// the sessions surface without a network call.
const list = vi.fn().mockResolvedValue([]);
const revokeOthers = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      list: (...args: unknown[]) => list(...args),
      revokeOthers: (...args: unknown[]) => revokeOthers(...args),
    }),
  },
}));

import SessionsPage from "@/app/backoffice/profile/sessions/page";

describe("Backoffice sessions page", () => {
  it("mounts the MySessions capability so the AC9 sessions list is reachable", async () => {
    render(<SessionsPage />);

    expect(screen.getByTestId("account-sessions__title")).toBeInTheDocument();
    expect(await screen.findByTestId("my-sessions")).toBeInTheDocument();
  });
});
