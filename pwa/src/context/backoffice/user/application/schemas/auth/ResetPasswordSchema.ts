import { z } from "zod";
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from "./passwordPolicy";

export const ResetPasswordSchema = z
  .object({
    password: z
      .string()
      .min(PASSWORD_MIN_LENGTH, "The password must be at least 8 characters.")
      .max(PASSWORD_MAX_LENGTH, "The password must not exceed 128 characters."),
    confirmPassword: z.string().min(1, "Confirm your password."),
  })
  .refine((v) => v.password === v.confirmPassword, {
    message: "Passwords do not match.",
    path: ["confirmPassword"],
  });
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
