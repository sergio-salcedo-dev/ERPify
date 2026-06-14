import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

/** Mirror of the API's email length cap. */
export const USER_EMAIL_MAX_LENGTH = 255;

export const UserCreateSchema = z.object({
  email: z
    .string({ error: "The email field is required." })
    .trim()
    .min(1, "The email field is required.")
    .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
    .email("Enter a valid email address."),
  roles: z.array(z.enum(Role)).min(1, "Select at least one role."),
  status: z.enum(UserStatus),
});
export type UserCreateFormValues = z.infer<typeof UserCreateSchema>;
