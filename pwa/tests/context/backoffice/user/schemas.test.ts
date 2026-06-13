import { describe, it, expect } from "vitest";
import { RegisterSchema } from "@/context/backoffice/user/application/schemas/auth/RegisterSchema";
import { ResetPasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { UserCreateSchema } from "@/context/backoffice/user/application/schemas/UserCreateSchema";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

describe("auth schemas", () => {
  it("RegisterSchema rejects mismatched passwords on confirmPassword", () => {
    const r = RegisterSchema.safeParse({
      email: "a@b.com",
      password: "password1",
      confirmPassword: "password2",
    });
    expect(r.success).toBe(false);
    if (!r.success) {
      expect(r.error.issues.some((i) => i.path.includes("confirmPassword"))).toBe(true);
    }
  });
  it("ResetPasswordSchema accepts matching passwords", () => {
    expect(
      ResetPasswordSchema.safeParse({ password: "password1", confirmPassword: "password1" })
        .success,
    ).toBe(true);
  });
  it("UserCreateSchema requires at least one role", () => {
    const bad = UserCreateSchema.safeParse({
      email: "a@b.com",
      roles: [],
      status: UserStatus.PENDING,
    });
    expect(bad.success).toBe(false);
    const ok = UserCreateSchema.safeParse({
      email: "a@b.com",
      roles: [Role.ADMIN],
      status: UserStatus.PENDING,
    });
    expect(ok.success).toBe(true);
  });
});
