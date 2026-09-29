"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Lock, Plus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { roleKeys, rolesApi } from "./api";

export function RolesPage() {
  return (
    <RequirePermission permission="roles.view">
      <RolesList />
    </RequirePermission>
  );
}

function RolesList() {
  const { can } = useSession();
  const [creating, setCreating] = useState(false);
  const roles = useQuery({ queryKey: roleKeys.all, queryFn: rolesApi.list });

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        description="Roles bundle permissions. Staff can hold several roles at once."
        actions={
          can("roles.create") && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden="true" /> New role
            </Button>
          )
        }
      />
      <Card>
        {roles.isPending ? (
          <LoadingState />
        ) : roles.isError ? (
          <ErrorState error={roles.error} onRetry={() => roles.refetch()} />
        ) : roles.data.length === 0 ? (
          <EmptyState title="No roles yet" />
        ) : (
          <ul className="divide-y divide-border">
            {roles.data.map((role) => (
              <li key={role.id}>
                <Link href={`/staff/roles/${role.id}`} className="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-surface-muted/60">
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 font-medium">
                      {role.name}
                      {role.is_system && (
                        <Badge>
                          <Lock className="size-3" aria-hidden="true" /> System
                        </Badge>
                      )}
                    </p>
                    {role.description && <p className="text-sm text-muted">{role.description}</p>}
                  </div>
                  <div className="flex gap-2 text-xs text-muted">
                    <span>{role.permissions?.length ?? 0} permissions</span>
                    <span aria-hidden="true">·</span>
                    <span>{role.users_count ?? 0} users</span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {can("roles.create") && <CreateRoleDialog open={creating} onClose={() => setCreating(false)} />}
    </>
  );
}

function CreateRoleDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");

  const mutation = useMutation({
    mutationFn: () => rolesApi.create({ name, description: description || null, permissions: [] }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: roleKeys.all });
      router.push(`/staff/roles/${res.data.id}`);
    },
  });

  const nameError = isApiError(mutation.error) ? mutation.error.field("name") : undefined;
  const generalError = mutation.error && !nameError ? errorMessage(mutation.error) : null;

  return (
    <Dialog
      open={open}
      onClose={() => {
        mutation.reset();
        onClose();
      }}
      title="New role"
      description="You'll choose its permissions on the next screen."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="create-role-form" loading={mutation.isPending} disabled={name.trim().length < 2}>
            Create role
          </Button>
        </>
      }
    >
      <form
        id="create-role-form"
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          mutation.mutate();
        }}
      >
        {generalError && <Alert tone="danger">{generalError}</Alert>}
        <Field label="Role name" error={nameError} required>
          <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={60} />
        </Field>
        <Field label="Description">
          <Input value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} />
        </Field>
      </form>
    </Dialog>
  );
}
