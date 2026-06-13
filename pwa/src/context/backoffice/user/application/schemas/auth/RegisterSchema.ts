import { z } from "zod";

export const RegisterSchema = z
  .object({
    email: z
      .string({ error: "The email field is required." })
      .trim()
      .min(1, "The email field is required.")
      .email("Enter a valid email address."),
    password: z.string().min(8, "The password must be at least 8 characters."),
    confirmPassword: z.string().min(1, "Confirm your password."),
  })
  .refine((v) => v.password === v.confirmPassword, {
    message: "Passwords do not match.",
    path: ["confirmPassword"],
  });
export type RegisterFormValues = z.infer<typeof RegisterSchema>;
