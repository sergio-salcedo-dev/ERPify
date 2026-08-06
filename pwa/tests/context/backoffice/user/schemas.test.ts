import { describe, it, expect } from "vitest";
import { AcceptInvitationSchema } from "@/context/backoffice/user/application/schemas/auth/AcceptInvitationSchema";
import { ChangePasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ChangePasswordSchema";
import { ResetPasswordSchema } from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import {
  PASSWORD_BLANK_MESSAGE,
  PASSWORD_TOO_SHORT_MESSAGE,
} from "@/context/backoffice/user/application/schemas/auth/passwordPolicy";
import { UserCreateSchema } from "@/context/backoffice/user/application/schemas/UserCreateSchema";
import { InviteUserSchema } from "@/context/backoffice/user/application/schemas/InviteUserSchema";
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

const GRINNING_FACE = "\u{1F600}";
const NO_BREAK_SPACE = "\u{00A0}";
const IDEOGRAPHIC_SPACE = "\u{3000}";

type ParseResult = { success: boolean; error?: { issues: { message: string }[] } };

/**
 * The three surfaces that create a credential. They are exercised together on purpose: the defect
 * this policy closes was that each declared its own bounds and one of them had already drifted.
 */
const NEW_PASSWORD_SURFACES: ReadonlyArray<[string, (password: string) => ParseResult]> = [
  [
    "ChangePasswordSchema",
    (password) =>
      ChangePasswordSchema.safeParse({ currentPassword: "whatever-it-was", newPassword: password }),
  ],
  ["ResetPasswordSchema", (password) => ResetPasswordSchema.safeParse({ password })],
  ["AcceptInvitationSchema", (password) => AcceptInvitationSchema.safeParse({ password })],
];

describe.each(NEW_PASSWORD_SURFACES)("%s honours the shared password policy", (_name, parse) => {
  it("counts code points rather than UTF-16 units", () => {
    // Ten UTF-16 units, five code points. Measured the `String.length` way this passed the client
    // and the API refused it as too short, under two rules both claiming "at least 8 characters".
    expect(parse(GRINNING_FACE.repeat(5)).success).toBe(false);
    // Eight code points is the floor, whatever `String.length` reports about them.
    expect(parse(GRINNING_FACE.repeat(8)).success).toBe(true);
  });

  it("rejects a credential made only of whitespace, ascii or not", () => {
    expect(parse(" ".repeat(8)).success).toBe(false);
    expect(parse(NO_BREAK_SPACE.repeat(8)).success).toBe(false);
    expect(parse(IDEOGRAPHIC_SPACE.repeat(8)).success).toBe(false);
  });

  it("reports one message per input, never a whitespace value doubling as too short", () => {
    const whitespaceOnly = parse(" ".repeat(8));

    expect(whitespaceOnly.error?.issues).toHaveLength(1);
    expect(whitespaceOnly.error?.issues[0]?.message).toBe(PASSWORD_BLANK_MESSAGE);

    const blank = parse("");

    expect(blank.error?.issues).toHaveLength(1);
    expect(blank.error?.issues[0]?.message).toBe(PASSWORD_TOO_SHORT_MESSAGE);
  });

  it("agrees with the API on both bounds", () => {
    expect(parse("a".repeat(8)).success).toBe(true);
    expect(parse("a".repeat(128)).success).toBe(true);
    expect(parse("a".repeat(129)).success).toBe(false);
  });
});

describe("ChangePasswordSchema", () => {
  it("leaves the credential being replaced outside the policy", () => {
    // A password minted under an older, wider rule must stay submittable: the endpoint that would
    // replace it is the last place that should refuse it for failing today's floor.
    const shortExisting = ChangePasswordSchema.safeParse({
      currentPassword: "old",
      newPassword: "a-brand-new-password",
    });

    expect(shortExisting.success).toBe(true);
  });

  it("still requires the credential being replaced to be present", () => {
    const absent = ChangePasswordSchema.safeParse({
      currentPassword: "",
      newPassword: "a-brand-new-password",
    });

    expect(absent.success).toBe(false);
  });

  it("measures the existing credential's ceiling in code points, like the API does", () => {
    // 200 astral characters: 200 code points, but 400 UTF-16 units. The API's ceiling on this field is
    // `Assert\Length(max: 255)`, which counts code points and accepts it — so a client rule written
    // against `String.length` would refuse at 127 characters a credential the server considers fine.
    const astral = "😀".repeat(200);

    const accepted = ChangePasswordSchema.safeParse({
      currentPassword: astral,
      newPassword: "a-brand-new-password",
    });

    expect(astral).toHaveLength(400);
    expect(accepted.success).toBe(true);
  });

  it("still refuses an existing credential past the ceiling the API enforces", () => {
    const over = ChangePasswordSchema.safeParse({
      currentPassword: "a".repeat(256),
      newPassword: "a-brand-new-password",
    });

    expect(over.success).toBe(false);
  });
});

describe("InviteUserSchema", () => {
  it("accepts an email with at least one role", () => {
    expect(InviteUserSchema.safeParse({ email: "a@b.com", roles: [Role.EDITOR] }).success).toBe(
      true,
    );
  });
  it("rejects a blank email", () => {
    expect(InviteUserSchema.safeParse({ email: "   ", roles: [Role.EDITOR] }).success).toBe(false);
  });
  it("rejects a malformed email", () => {
    expect(
      InviteUserSchema.safeParse({ email: "not-an-email", roles: [Role.EDITOR] }).success,
    ).toBe(false);
  });
  it("requires at least one role", () => {
    expect(InviteUserSchema.safeParse({ email: "a@b.com", roles: [] }).success).toBe(false);
  });
  it("accepts the backend role vocabulary and rejects an unknown role", () => {
    const all = InviteUserSchema.safeParse({
      email: "a@b.com",
      roles: [Role.VIEWER, Role.EDITOR, Role.MANAGER, Role.ADMIN, Role.AUDIT_READER],
    });
    expect(all.success).toBe(true);
    const stale = InviteUserSchema.safeParse({ email: "a@b.com", roles: ["EMPLOYEE"] });
    expect(stale.success).toBe(false);
  });
  it("carries no status field — an INVITED identity has none yet", () => {
    const parsed = InviteUserSchema.parse({ email: "a@b.com", roles: [Role.ADMIN] });
    expect(parsed).not.toHaveProperty("status");
  });
});
