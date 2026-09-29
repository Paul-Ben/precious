"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { meQueryKey, useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { PermissionGroup, Role } from "@/lib/api/types";
import { roleKeys, rolesApi } from "./api";
import { PermissionMatrix } from "./permission-matrix";

export function RoleDetail({ id }: { id: string }) {
  return (
    <RequirePermission permission="roles.view">
      <RoleDetailInner id={id} />
    </RequirePermission>
  );
}

function RoleDetailInner({ id }: { id: string }) {
  const role = useQuery({ queryKey: roleKeys.detail(id), queryFn: () => rolesApi.get(id) });
  const catalog = useQuery({ queryKey: roleKeys.permissions, queryFn: rolesApi.permissions, staleTime: Infinity });

  return (
    <>
      <Link href="/staff/roles" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> All roles
      </Link>
      {role.isPending || catalog.isPending ? (
        <LoadingState />
      ) : role.isError ? (
        <ErrorState error={role.error} onRetry={() => role.refetch()} />
      ) : catalog.isError ? (
        <ErrorState error={catalog.error} onRetry={() => catalog.refetch()} />
      ) : (
        <Loaded key={role.data.id} role={role.data} groups={catalog.data} />
      )}
    </>
  );
}

function Loaded({ role, groups }: { role: Role; groups: PermissionGroup[] }) {
  const { user, can } = useSession();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [permissions, setPermissions] = useState<string[]>(role.permissions ?? []);
  const [details, setDetails] = useState({ name: role.name, description: role.description ?? "" });
  const [feedback, setFeedback] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const isSuperRole = role.name === "Super Administrator";
  const isCustomerRole = role.name === "Customer";
  const holdsRole = user.roles.includes(role.name) && !user.is_super_admin;
  const canEditPermissions = can("roles.update") && !isSuperRole && !isCustomerRole && !holdsRole;
  const grantable = (p: string) => user.is_super_admin || (user.permissions ?? []).includes(p);

  const onSuccess = async (text: string, updated?: Role) => {
    if (updated) queryClient.setQueryData(roleKeys.detail(role.id), updated);
    await queryClient.invalidateQueries({ queryKey: roleKeys.all });
    await queryClient.invalidateQueries({ queryKey: meQueryKey });
    setFeedback({ tone: "success", text });
  };
  const onError = (error: unknown) => setFeedback({ tone: "danger", text: errorMessage(error) });

  const savePermissions = useMutation({
    mutationFn: () => rolesApi.syncPermissions(role.id, permissions),
    onSuccess: (res) => onSuccess(res.message, res.data),
    onError,
  });
  const saveDetails = useMutation({
    mutationFn: () =>
      rolesApi.update(role.id, {
        ...(role.is_system ? {} : { name: details.name }),
        description: details.description || null,
      }),
    onSuccess: (res) => onSuccess(res.message, res.data),
    onError,
  });
  const remove = useMutation({
    mutationFn: () => rolesApi.remove(role.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: roleKeys.all });
      router.push("/staff/roles");
    },
    onError: (e) => {
      setConfirmDelete(false);
      onError(e);
    },
  });

  const dirty = [...permissions].sort().join() !== [...(role.permissions ?? [])].sort().join();

  return (
    <>
      <PageHeader
        title={role.name}
        description={`${role.users_count ?? 0} user(s) hold this role.`}
        actions={
          <>
            {role.is_system && <Badge>System role</Badge>}
            {can("roles.delete") && !role.is_system && (
              <Button variant="danger" size="sm" onClick={() => setConfirmDelete(true)}>
                Delete role
              </Button>
            )}
          </>
        }
      />

      {feedback && (
        <Alert tone={feedback.tone} className="mb-4">
          {feedback.text}
        </Alert>
      )}

      <div className="space-y-6">
        {can("roles.update") && (
          <Card>
            <CardHeader title="Details" />
            <CardBody>
              <form
                className="grid gap-4 md:grid-cols-[1fr_2fr_auto] md:items-end"
                onSubmit={(e) => {
                  e.preventDefault();
                  setFeedback(null);
                  saveDetails.mutate();
                }}
              >
                <Field label="Name" hint={role.is_system ? "System roles can't be renamed." : undefined}>
                  <Input
                    value={details.name}
                    disabled={role.is_system}
                    onChange={(e) => setDetails((d) => ({ ...d, name: e.target.value }))}
                  />
                </Field>
                <Field label="Description">
                  <Input value={details.description} onChange={(e) => setDetails((d) => ({ ...d, description: e.target.value }))} />
                </Field>
                <Button type="submit" variant="outline" loading={saveDetails.isPending}>
                  Save details
                </Button>
              </form>
            </CardBody>
          </Card>
        )}

        <Card>
          <CardHeader
            title="Permissions"
            description={
              isSuperRole
                ? "Super Administrators always have every permission."
                : isCustomerRole
                  ? "Customers only ever access their own records."
                  : holdsRole
                    ? "You hold this role, so you can't change its permissions."
                    : "You can only grant permissions you hold yourself."
            }
            actions={
              canEditPermissions && (
                <Button
                  disabled={!dirty}
                  loading={savePermissions.isPending}
                  onClick={() => {
                    setFeedback(null);
                    savePermissions.mutate();
                  }}
                >
                  Save permissions
                </Button>
              )
            }
          />
          <CardBody>
            <PermissionMatrix
              groups={groups}
              value={permissions}
              onChange={setPermissions}
              disabled={!canEditPermissions}
              grantable={grantable}
            />
          </CardBody>
        </Card>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => remove.mutate()}
        loading={remove.isPending}
        title={`Delete ${role.name}?`}
        confirmLabel="Delete role"
      >
        This can&apos;t be undone. Roles that are still assigned to users can&apos;t be deleted.
      </ConfirmDialog>
    </>
  );
}
