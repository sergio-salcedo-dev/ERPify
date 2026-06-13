import { z } from "zod";
import { USER_EMAIL_MAX_LENGTH } from "../UserCreateSchema";
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from "./passwordPolicy";

export const RegisterSchema = z
  .object({
    email: z
      .string({ error: "The email field is required." })
      .trim()
      .min(1, "The email field is required.")
      .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
      .email("Enter a valid email address."),
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
export type RegisterFormValues = z.infer<typeof RegisterSchema>;
