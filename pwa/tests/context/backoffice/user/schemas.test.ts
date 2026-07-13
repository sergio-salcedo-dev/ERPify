import { describe, it, expect } from "vitest";
import { AcceptInvitationSchema } from "@/context/backoffice/user/application/schemas/auth/AcceptInvitationSchema";
import { ResetPasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { UserCreateSchema } from "@/context/backoffice/user/application/schemas/UserCreateSchema";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

describe("auth schemas", () => {
  it("ResetPasswordSchema rejects mismatched passwords on confirmPassword", () => {
    const r = ResetPasswordSchema.safeParse({
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
      status: UserStatus.INVITED,
    });
    expect(bad.success).toBe(false);
    const ok = UserCreateSchema.safeParse({
      email: "a@b.com",
      roles: [Role.ADMIN],
      status: UserStatus.INVITED,
    });
    expect(ok.success).toBe(true);
  });
  it("AcceptInvitationSchema accepts a password within bounds (single field, no confirm)", () => {
    expect(AcceptInvitationSchema.safeParse({ password: "password1" }).success).toBe(true);
  });
  it("AcceptInvitationSchema rejects a too-short password", () => {
    expect(AcceptInvitationSchema.safeParse({ password: "short" }).success).toBe(false);
  });
  it("AcceptInvitationSchema rejects a too-long password", () => {
    const longPassword = "a".repeat(129);
    expect(AcceptInvitationSchema.safeParse({ password: longPassword }).success).toBe(false);
  });
});
