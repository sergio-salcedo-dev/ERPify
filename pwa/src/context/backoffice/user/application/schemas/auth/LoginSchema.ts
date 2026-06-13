import { z } from "zod";

export const LoginSchema = z.object({
  email: z
    .string({ error: "The email field is required." })
    .trim()
    .min(1, "The email field is required.")
    .email("Enter a valid email address."),
  password: z
    .string({ error: "The password field is required." })
    .min(1, "The password field is required."),
});
export type LoginFormValues = z.infer<typeof LoginSchema>;
