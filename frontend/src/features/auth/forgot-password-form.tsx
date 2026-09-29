"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { errorMessage } from "@/lib/api/errors";
import { applyServerErrors } from "@/lib/forms";
import { forgotPasswordSchema, type ForgotPasswordValues } from "@/lib/validation/auth";

export function ForgotPasswordForm() {
  const [sent, setSent] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordValues>({ resolver: zodResolver(forgotPasswordSchema), defaultValues: { email: "" } });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      const res = await api.post<null>("auth/forgot-password", values);
      setSent(res.message);
    } catch (error) {
      if (!applyServerErrors(error, setError, ["email"])) setFormError(errorMessage(error));
    }
  });

  if (sent) return <Alert tone="success" title="Check your email">{sent}</Alert>;

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="danger">{formError}</Alert>}
      <Field label="Email address" error={errors.email?.message} required>
        <Input type="email" autoComplete="email" inputMode="email" {...register("email")} />
      </Field>
      <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>
        Send reset link
      </Button>
    </form>
  );
}
