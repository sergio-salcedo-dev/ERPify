import { describe, it, expect } from "vitest";
import { AcceptInvitationSchema } from "@/context/backoffice/user/application/schemas/auth/AcceptInvitationSchema";
import { ResetPasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { UserCreateSchema } from "@/context/backoffice/user/application/schemas/UserCreateSchema";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

describe("auth schemas", () => {
  it("ResetPasswordSchema accepts a password within bounds (single field, no confirm)", () => {
    expect(ResetPasswordSchema.safeParse({ password: "password1" }).success).toBe(true);
  });
  it("ResetPasswordSchema rejects a too-short password", () => {
    expect(ResetPasswordSchema.safeParse({ password: "short" }).success).toBe(false);
  });
  it("ResetPasswordSchema rejects a too-long password", () => {
    const longPassword = "a".repeat(129);
    expect(ResetPasswordSchema.safeParse({ password: longPassword }).success).toBe(false);
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
  it("UserCreateSchema accepts the backend role vocabulary and rejects an unknown role", () => {
    const all = UserCreateSchema.safeParse({
      email: "a@b.com",
      roles: [Role.VIEWER, Role.EDITOR, Role.MANAGER, Role.ADMIN, Role.AUDIT_READER],
      status: UserStatus.ACTIVE,
    });
    expect(all.success).toBe(true);
    // A role that is not part of the backend enum (a stale vocabulary) is rejected.
    const stale = UserCreateSchema.safeParse({
      email: "a@b.com",
      roles: ["EMPLOYEE"],
      status: UserStatus.ACTIVE,
    });
    expect(stale.success).toBe(false);
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
