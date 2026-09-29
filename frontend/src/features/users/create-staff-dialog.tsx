"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { errorMessage } from "@/lib/api/errors";
import type { Role } from "@/lib/api/types";
import { applyServerErrors } from "@/lib/forms";
import { emailSchema, phoneSchema } from "@/lib/validation/auth";
import { userKeys, usersApi } from "./api";

const schema = z.object({
  name: z.string().trim().min(2, "Enter the staff member's name.").max(120),
  email: emailSchema,
  phone: phoneSchema.optional(),
  roles: z.array(z.string()).min(1, "Choose at least one role."),
  two_factor_enabled: z.boolean(),
});
type Values = z.infer<typeof schema>;

export function CreateStaffDialog({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: Role[] }) {
  const queryClient = useQueryClient();
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<string | null>(null);

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", email: "", phone: "", roles: [], two_factor_enabled: false },
  });
  const { register, handleSubmit, setError, reset, formState } = form;

  const mutation = useMutation({
    mutationFn: (values: Values) => usersApi.createStaff({ ...values, phone: values.phone || null }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: userKeys.all });
      setCreated(res.message);
      reset();
    },
    onError: (error) => {
      if (!applyServerErrors(error, setError, ["name", "email", "phone", "roles"])) setFormError(errorMessage(error));
    },
  });

  function close() {
    setFormError(null);
    setCreated(null);
    reset();
    onClose();
  }

  const staffRoles = roles.filter((r) => r.name !== "Customer");

  return (
    <Dialog
      open={open}
      onClose={close}
      title="Add staff member"
      description="They'll receive an email with a temporary password and must change it at first sign-in."
      footer={
        created ? (
          <Button onClick={close}>Done</Button>
        ) : (
          <>
            <Button variant="ghost" onClick={close}>
              Cancel
            </Button>
            <Button type="submit" form="create-staff-form" loading={mutation.isPending}>
              Create account
            </Button>
          </>
        )
      }
    >
      {created ? (
        <Alert tone="success" title="Account created">
          {created}
        </Alert>
      ) : (
        <form
          id="create-staff-form"
          noValidate
          className="space-y-4"
          onSubmit={handleSubmit((values) => {
            setFormError(null);
            mutation.mutate(values);
          })}
        >
          {formError && <Alert tone="danger">{formError}</Alert>}
          <Field label="Full name" error={formState.errors.name?.message} required>
            <Input autoComplete="off" {...register("name")} />
          </Field>
          <Field label="Email address" error={formState.errors.email?.message} required>
            <Input type="email" autoComplete="off" {...register("email")} />
          </Field>
          <Field label="Phone number" error={formState.errors.phone?.message}>
            <Input type="tel" autoComplete="off" {...register("phone")} />
          </Field>
          <fieldset>
            <legend className="mb-1 text-sm font-medium">
              Roles <span className="text-danger">*</span>
            </legend>
            <p className="mb-2 text-xs text-muted">A person can hold several roles; their permissions combine.</p>
            <div className="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-border p-2 sm:grid-cols-2">
              {staffRoles.map((role) => (
                <Checkbox key={role.id} label={role.name} value={role.name} {...register("roles")} />
              ))}
            </div>
            {formState.errors.roles && (
              <p className="mt-1 text-xs font-medium text-danger" role="alert">
                {formState.errors.roles.message}
              </p>
            )}
          </fieldset>
          <Checkbox
            label="Require two-factor sign-in"
            description="Always on for Administrators and Super Administrators."
            {...register("two_factor_enabled")}
          />
        </form>
      )}
    </Dialog>
  );
}
