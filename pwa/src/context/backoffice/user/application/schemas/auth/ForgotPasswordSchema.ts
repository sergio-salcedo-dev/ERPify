import { z } from "zod";

export const ForgotPasswordSchema = z.object({
  email: z
    .string({ error: "The email field is required." })
    .trim()
    .min(1, "The email field is required.")
    .email("Enter a valid email address."),
});
export type ForgotPasswordFormValues = z.infer<typeof ForgotPasswordSchema>;
