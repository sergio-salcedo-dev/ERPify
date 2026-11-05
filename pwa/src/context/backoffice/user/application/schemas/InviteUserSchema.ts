import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { USER_EMAIL_MAX_LENGTH } from "./UserCreateSchema";
import { existingPasswordSchema } from "./auth/passwordPolicy";

/**
 * Invitation alta form: an email plus at least one role. No password for the invitee (they set it on accept)
 * and no status (INVITED by construction). Field messages mirror the API's 422 strings — the request DTO's
 * `#[Assert\NotBlank]` / `#[Assert\Email]` / `#[Assert\Count]` — so one set of assertions covers both ends and a
 * server violation maps cleanly onto the matching field.
 *
 * `currentPassword` is the INVITER's own password, and it is required if and only if ADMIN is among the roles:
 * granting administrator is authority a session alone does not prove, while every other role is not. It reuses
 * `existingPasswordSchema` (presence and the API's ceiling, never the new-password policy), because the only
 * thing that can be wrong with a working password is that it does not match.
 */
export const InviteUserSchema = z
  .object({
    email: z
      .string({ error: "An email is required." })
      .trim()
      .min(1, "An email is required.")
      .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
      .email("Enter a valid email address."),
    roles: z.array(z.enum(Role)).min(1, "Select at least one role."),
    currentPassword: z.string().optional(),
  })
  .superRefine((value, ctx) => {
    if (!value.roles.includes(Role.ADMIN)) return;
    const proof = existingPasswordSchema.safeParse(value.currentPassword ?? "");
    if (proof.success) return;
    for (const issue of proof.error.issues) {
      ctx.addIssue({ code: "custom", path: ["currentPassword"], message: issue.message });
    }
  });
export type InviteUserFormValues = z.infer<typeof InviteUserSchema>;
