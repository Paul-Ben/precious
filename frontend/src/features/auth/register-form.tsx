"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { errorMessage } from "@/lib/api/errors";
import type { User } from "@/lib/api/types";
import { applyServerErrors } from "@/lib/forms";
import { registerSchema, type RegisterValues } from "@/lib/validation/auth";

export function RegisterForm() {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: "", email: "", phone: "", password: "", password_confirmation: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await api.post<{ user: User }>("auth/register", { ...values, phone: values.phone || null });
      router.replace("/account");
      router.refresh();
    } catch (error) {
      const applied = applyServerErrors(error, setError, ["name", "email", "phone", "password", "password_confirmation"]);
      if (!applied) setFormError(errorMessage(error));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="danger">{formError}</Alert>}
      <Field label="Full name" error={errors.name?.message} required>
        <Input autoComplete="name" {...register("name")} />
      </Field>
      <Field label="Email address" error={errors.email?.message} required>
        <Input type="email" autoComplete="email" inputMode="email" {...register("email")} />
      </Field>
      <Field label="Phone number" error={errors.phone?.message} hint="Optional. Include your country code, e.g. +234.">
        <Input type="tel" autoComplete="tel" inputMode="tel" {...register("phone")} />
      </Field>
      <Field
        label="Password"
        error={errors.password?.message}
        hint="At least 10 characters with upper and lower case letters, a number and a symbol."
        required
      >
        <Input type="password" autoComplete="new-password" {...register("password")} />
      </Field>
      <Field label="Confirm password" error={errors.password_confirmation?.message} required>
        <Input type="password" autoComplete="new-password" {...register("password_confirmation")} />
      </Field>
      <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>
        Create account
      </Button>
    </form>
  );
}
