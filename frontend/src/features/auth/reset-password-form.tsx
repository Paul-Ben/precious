"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { applyServerErrors } from "@/lib/forms";
import { resetPasswordSchema, type ResetPasswordValues } from "@/lib/validation/auth";

export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
  const [done, setDone] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  if (!token || !email) {
    return <Alert tone="danger">This reset link is incomplete. Please request a new one.</Alert>;
  }

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await api.post<null>("auth/reset-password", { ...values, token, email });
      setDone(true);
    } catch (error) {
      if (isApiError(error) && error.field("email")) {
        setFormError(error.field("email")!);
        return;
      }
      if (!applyServerErrors(error, setError, ["password", "password_confirmation"])) setFormError(errorMessage(error));
    }
  });

  if (done) {
    return (
      <div className="space-y-4">
        <Alert tone="success" title="Password updated">
          You can now sign in with your new password.
        </Alert>
        <Link href="/login" className="block text-center text-sm font-medium underline underline-offset-4">
          Go to sign in
        </Link>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="danger">{formError}</Alert>}
      <p className="text-sm text-muted">
        Resetting the password for <span className="font-medium text-foreground">{email}</span>.
      </p>
      <Field
        label="New password"
        error={errors.password?.message}
        hint="At least 10 characters with upper and lower case letters, a number and a symbol."
        required
      >
        <Input type="password" autoComplete="new-password" {...register("password")} />
      </Field>
      <Field label="Confirm new password" error={errors.password_confirmation?.message} required>
        <Input type="password" autoComplete="new-password" {...register("password_confirmation")} />
      </Field>
      <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>
        Reset password
      </Button>
    </form>
  );
}
