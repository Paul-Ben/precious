"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
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
import { homeFor } from "@/lib/auth/permissions";
import { applyServerErrors } from "@/lib/forms";
import { changePasswordSchema, type ChangePasswordValues } from "@/lib/validation/auth";

export function ChangePasswordForm({ forced = false }: { forced?: boolean }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [formError, setFormError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordValues>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { current_password: "", password: "", password_confirmation: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      const res = await api.post<User>("auth/password/change", values);
      queryClient.setQueryData(["me"], res.data);
      if (forced) {
        router.replace(homeFor(res.data));
        router.refresh();
      } else {
        setSuccess(true);
        reset();
      }
    } catch (error) {
      const applied = applyServerErrors(error, setError, ["current_password", "password", "password_confirmation"]);
      if (!applied) setFormError(errorMessage(error));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="danger">{formError}</Alert>}
      {success && <Alert tone="success">Your password has been changed. Other devices have been signed out.</Alert>}
      <Field label={forced ? "Temporary password" : "Current password"} error={errors.current_password?.message} required>
        <Input type="password" autoComplete="current-password" {...register("current_password")} />
      </Field>
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
        {forced ? "Set new password" : "Change password"}
      </Button>
    </form>
  );
}
