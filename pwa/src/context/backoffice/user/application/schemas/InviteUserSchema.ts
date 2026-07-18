import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { USER_EMAIL_MAX_LENGTH } from "./UserCreateSchema";

/**
 * Invitation alta form: an email plus at least one role. No password (the invitee sets it on accept) and no
 * status (INVITED by construction). Field messages mirror the API's 422 strings — the request DTO's
 * `#[Assert\NotBlank]` / `#[Assert\Email]` / `#[Assert\Count]` — so one set of assertions covers both ends and a
 * server violation maps cleanly onto the matching field.
 */
export const InviteUserSchema = z.object({
  email: z
    .string({ error: "An email is required." })
    .trim()
    .min(1, "An email is required.")
    .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
    .email("Enter a valid email address."),
  roles: z.array(z.enum(Role)).min(1, "Select at least one role."),
});
export type InviteUserFormValues = z.infer<typeof InviteUserSchema>;
