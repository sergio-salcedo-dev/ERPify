import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const find = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());

// The page reads its item through `useResourceItem`, which resolves the repository from the container; the
// `Can` gate hydrates the session from `/me` through the same container. Dispatch by token so the gate sees a
// real permission set while the read path hits the mocked repository.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeUserRepository") return { find };
      return { me: (...args: unknown[]) => me(...args) };
    },
  },
}));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c" }),
}));

import UserDetailPage from "@/app/backoffice/users/[id]/page";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { Identity } from "@/context/shared/access/domain/Identity";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const ADMIN: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ],
};

function problem(status: number, type: string): ProblemDetails {
  return {
    type,
    title: "Something went wrong.",
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b60",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b61",
  };
}

describe("UserDetailPage error states", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    me.mockResolvedValue(ADMIN);
  });

  it("reports a 404 as a user that may have been deleted", async () => {
    find.mockRejectedValue(new HttpError(problem(404, "user-not-found")));

    render(
      <AuthProvider>
        <UserDetailPage />
      </AuthProvider>,
    );

    expect(await screen.findByTestId("users-detail__not-found")).toBeInTheDocument();
    expect(screen.queryByTestId("users-detail__error")).not.toBeInTheDocument();
  });

  it("does not claim a deletion when the load fails for any other reason", async () => {
    // A transport blip or a 500 right after a successful mutation must not read as "this user is gone".
    find.mockRejectedValue(new HttpError(problem(500, "unhandled-exception")));

    render(
      <AuthProvider>
        <UserDetailPage />
      </AuthProvider>,
    );

    expect(await screen.findByTestId("users-detail__error")).toBeInTheDocument();
    expect(screen.queryByTestId("users-detail__not-found")).not.toBeInTheDocument();
  });

  it("offers a retry when the failure carries no problem envelope", async () => {
    find.mockRejectedValue(new Error("network down"));

    render(
      <AuthProvider>
        <UserDetailPage />
      </AuthProvider>,
    );

    expect(await screen.findByTestId("users-detail__error")).toBeInTheDocument();
    expect(screen.getByTestId("users-detail__retry")).toBeInTheDocument();
    expect(screen.queryByTestId("users-detail__not-found")).not.toBeInTheDocument();
  });
});
