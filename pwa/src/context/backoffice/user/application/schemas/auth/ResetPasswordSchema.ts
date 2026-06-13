import { z } from "zod";

export const ResetPasswordSchema = z
  .object({
    password: z.string().min(8, "The password must be at least 8 characters."),
    confirmPassword: z.string().min(1, "Confirm your password."),
  })
  .refine((v) => v.password === v.confirmPassword, {
    message: "Passwords do not match.",
    path: ["confirmPassword"],
  });
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
