import { describe, it, expect } from "vitest";
import { InMemoryUserRepository } from "@/context/backoffice/user/infrastructure/InMemoryUserRepository";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

const input = {
  email: "unique@erpify.dev",
  roles: [Role.ADMIN],
  status: UserStatus.ACTIVE,
  permissions: [],
};

describe("InMemoryUserRepository email uniqueness", () => {
  it("creates a user with a fresh email", async () => {
    const created = await new InMemoryUserRepository().create(input);
    expect(created.email).toBe(input.email);
  });

  it("rejects a duplicate email with a user-email-conflict 409", async () => {
    const repo = new InMemoryUserRepository();
    await repo.create(input);
    await expect(repo.create({ ...input, email: "UNIQUE@erpify.dev" })).rejects.toMatchObject({
      problem: { type: UserProblemType.EMAIL_CONFLICT, status: HttpStatus.CONFLICT },
    });
    await expect(repo.create(input)).rejects.toBeInstanceOf(HttpError);
  });
});
