import { z } from "zod";

/**
 * Mirrors the backend password policy (Password::defaults): at least 10
 * characters with upper- and lower-case letters, a number and a symbol.
 * The backend re-validates everything.
 */
export const passwordSchema = z
  .string()
  .min(10, "Use at least 10 characters.")
  .regex(/[a-z]/, "Include a lower-case letter.")
  .regex(/[A-Z]/, "Include an upper-case letter.")
  .regex(/[0-9]/, "Include a number.")
  .regex(/[^A-Za-z0-9]/, "Include a symbol.");

export const emailSchema = z.string().trim().min(1, "Enter your email address.").email("Enter a valid email address.");

export const phoneSchema = z
  .string()
  .trim()
  .regex(/^\+?[0-9]{7,15}$/, "Enter a valid phone number, e.g. +2348012345678.")
  .or(z.literal(""));

export const loginSchema = z.object({
  email: emailSchema,
  password: z.string().min(1, "Enter your password."),
});
export type LoginValues = z.infer<typeof loginSchema>;

export const otpSchema = z.object({
  code: z
    .string()
    .trim()
    .regex(/^\d{6}$/, "Enter the 6-digit code."),
});
export type OtpValues = z.infer<typeof otpSchema>;

export const registerSchema = z
  .object({
    name: z.string().trim().min(2, "Enter your full name.").max(120),
    email: emailSchema,
    phone: phoneSchema.optional(),
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  });
export type RegisterValues = z.infer<typeof registerSchema>;

export const forgotPasswordSchema = z.object({ email: emailSchema });
export type ForgotPasswordValues = z.infer<typeof forgotPasswordSchema>;

export const resetPasswordSchema = z
  .object({
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  });
export type ResetPasswordValues = z.infer<typeof resetPasswordSchema>;

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, "Enter your current password."),
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords do not match.",
  })
  .refine((v) => v.password !== v.current_password, {
    path: ["password"],
    message: "Choose a password different from your current one.",
  });
export type ChangePasswordValues = z.infer<typeof changePasswordSchema>;
