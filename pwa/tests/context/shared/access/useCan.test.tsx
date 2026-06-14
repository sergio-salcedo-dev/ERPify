import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Can } from "@/context/shared/access/infrastructure/ui/Can";
import { Permission } from "@/context/shared/access/domain/Permission";

describe("<Can>", () => {
  it("renders children when the seeded admin holds the permission", () => {
    render(
      <AuthProvider>
        <Can permission={Permission.USERS_WRITE}>
          <button>create</button>
        </Can>
      </AuthProvider>,
    );
    expect(screen.getByRole("button", { name: "create" })).toBeInTheDocument();
  });
});
