"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { meQueryKey, useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { User } from "@/lib/api/types";
import { formatDateTime } from "@/lib/utils";
import { rolesApi, userKeys, usersApi } from "./api";

export function UserDetail({ id }: { id: string }) {
  return (
    <RequirePermission permission="users.view">
      <UserDetailInner id={id} />
    </RequirePermission>
  );
}

function UserDetailInner({ id }: { id: string }) {
  const user = useQuery({ queryKey: userKeys.detail(id), queryFn: () => usersApi.get(id) });

  return (
    <>
      <Link href="/staff/users" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> All users
      </Link>
      {user.isPending ? (
        <LoadingState />
      ) : user.isError ? (
        <ErrorState error={user.error} onRetry={() => user.refetch()} />
      ) : (
        <Loaded user={user.data} />
      )}
    </>
  );
}

function useUserMutation<TArgs>(id: string, fn: (args: TArgs) => Promise<{ message: string; data: User | null }>) {
  const queryClient = useQueryClient();
  const [feedback, setFeedback] = useState<{ tone: "success" | "danger"; text: string } | null>(null);

  const mutation = useMutation({
    mutationFn: fn,
    onSuccess: async (res) => {
      if (res.data) queryClient.setQueryData(userKeys.detail(id), res.data);
      await queryClient.invalidateQueries({ queryKey: userKeys.all });
      await queryClient.invalidateQueries({ queryKey: meQueryKey });
      setFeedback({ tone: "success", text: res.message });
    },
    onError: (error) => setFeedback({ tone: "danger", text: errorMessage(error) }),
  });

  return { mutation, feedback, setFeedback };
}

function Loaded({ user }: { user: User }) {
  const { user: me, can } = useSession();
  const isSelf = me.id === user.id;

  return (
    <>
      <PageHeader
        title={user.name}
        description={user.email}
        actions={
          <>
            <Badge tone={user.type === "staff" ? "info" : "neutral"}>{user.type === "staff" ? "Staff" : "Customer"}</Badge>
            <Badge tone={user.status === "active" ? "success" : "danger"}>{user.status === "active" ? "Active" : "Suspended"}</Badge>
          </>
        }
      />
      <div className="grid gap-6 xl:grid-cols-2">
        {/* Forms keep local edits; after a save the local state equals the saved state. */}
        <ProfileCard key={`profile-${user.id}`} user={user} editable={can("users.update")} />
        <RolesCard key={`roles-${user.id}`} user={user} editable={can("roles.assign") && !isSelf} isSelf={isSelf} />
        {can("users.update") && !isSelf && <AccountActionsCard user={user} />}
        <Card>
          <CardHeader title="Activity" />
          <CardBody>
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-muted">Last sign-in</dt>
                <dd>{formatDateTime(user.last_login_at)}</dd>
              </div>
              <div>
                <dt className="text-muted">Created</dt>
                <dd>{formatDateTime(user.created_at)}</dd>
              </div>
              <div>
                <dt className="text-muted">Two-factor sign-in</dt>
                <dd>{user.two_factor_required ? "Required" : "Off"}</dd>
              </div>
              <div>
                <dt className="text-muted">Password</dt>
                <dd>{user.must_change_password ? "Temporary - must change" : "Set by user"}</dd>
              </div>
            </dl>
            {can("audit.view") && (
              <Link href={`/staff/audit-logs?auditable_type=user&auditable_id=${user.id}`} className="mt-4 inline-block text-sm font-medium underline underline-offset-4">
                View audit trail
              </Link>
            )}
          </CardBody>
        </Card>
      </div>
    </>
  );
}

function ProfileCard({ user, editable }: { user: User; editable: boolean }) {
  const [values, setValues] = useState({ name: user.name, email: user.email, phone: user.phone ?? "", two_factor_enabled: user.two_factor_enabled });
  const { mutation, feedback, setFeedback } = useUserMutation(user.id, () =>
    usersApi.update(user.id, { ...values, phone: values.phone || null }),
  );

  return (
    <Card>
      <CardHeader title="Profile" />
      <CardBody>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            setFeedback(null);
            mutation.mutate(undefined);
          }}
        >
          {feedback && <Alert tone={feedback.tone}>{feedback.text}</Alert>}
          <Field label="Name">
            <Input value={values.name} disabled={!editable} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
          </Field>
          <Field label="Email">
            <Input type="email" value={values.email} disabled={!editable} onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))} />
          </Field>
          <Field label="Phone">
            <Input type="tel" value={values.phone} disabled={!editable} onChange={(e) => setValues((v) => ({ ...v, phone: e.target.value }))} />
          </Field>
          <Checkbox
            label="Require two-factor sign-in"
            description="Always required for Administrators and Super Administrators."
            checked={values.two_factor_enabled}
            disabled={!editable}
            onChange={(e) => setValues((v) => ({ ...v, two_factor_enabled: e.target.checked }))}
          />
          {editable && (
            <Button type="submit" loading={mutation.isPending}>
              Save changes
            </Button>
          )}
        </form>
      </CardBody>
    </Card>
  );
}

function RolesCard({ user, editable, isSelf }: { user: User; editable: boolean; isSelf: boolean }) {
  const roles = useQuery({ queryKey: ["roles"], queryFn: rolesApi.list, enabled: editable });
  const [selected, setSelected] = useState<string[]>(user.roles);
  const { mutation, feedback, setFeedback } = useUserMutation(user.id, () => usersApi.syncRoles(user.id, selected));

  const available = (roles.data ?? []).filter((r) => (user.type === "staff" ? r.name !== "Customer" : r.name === "Customer"));
  const dirty = [...selected].sort().join("|") !== [...user.roles].sort().join("|");

  return (
    <Card>
      <CardHeader title="Roles" description="Permissions from all roles are combined." />
      <CardBody className="space-y-4">
        {feedback && <Alert tone={feedback.tone}>{feedback.text}</Alert>}
        {!editable ? (
          <>
            <div className="flex flex-wrap gap-2">
              {user.roles.map((r) => (
                <Badge key={r} tone="brand">
                  {r}
                </Badge>
              ))}
            </div>
            {isSelf && <p className="text-xs text-muted">You can&apos;t change your own roles.</p>}
          </>
        ) : roles.isPending ? (
          <LoadingState label="Loading roles…" />
        ) : roles.isError ? (
          <ErrorState error={roles.error} onRetry={() => roles.refetch()} />
        ) : (
          <>
            <div className="grid gap-1 sm:grid-cols-2">
              {available.map((role) => (
                <Checkbox
                  key={role.id}
                  label={role.name}
                  description={role.description ?? undefined}
                  checked={selected.includes(role.name)}
                  onChange={(e) =>
                    setSelected((s) => (e.target.checked ? [...s, role.name] : s.filter((n) => n !== role.name)))
                  }
                />
              ))}
            </div>
            <Button
              disabled={!dirty || selected.length === 0}
              loading={mutation.isPending}
              onClick={() => {
                setFeedback(null);
                mutation.mutate(undefined);
              }}
            >
              Save roles
            </Button>
          </>
        )}
      </CardBody>
    </Card>
  );
}

function AccountActionsCard({ user }: { user: User }) {
  const [confirm, setConfirm] = useState<null | "suspend" | "activate" | "password">(null);
  const { mutation, feedback, setFeedback } = useUserMutation(user.id, (action: "suspend" | "activate" | "password") => {
    if (action === "suspend") return usersApi.suspend(user.id);
    if (action === "activate") return usersApi.activate(user.id);
    return usersApi.temporaryPassword(user.id);
  });

  const run = (action: "suspend" | "activate" | "password") => {
    setFeedback(null);
    mutation.mutate(action, { onSettled: () => setConfirm(null) });
  };

  return (
    <Card>
      <CardHeader title="Account actions" />
      <CardBody className="space-y-4">
        {feedback && <Alert tone={feedback.tone}>{feedback.text}</Alert>}
        <div className="flex flex-wrap gap-2">
          {user.status === "active" ? (
            <Button variant="danger" onClick={() => setConfirm("suspend")}>
              Suspend account
            </Button>
          ) : (
            <Button variant="outline" onClick={() => setConfirm("activate")}>
              Reactivate account
            </Button>
          )}
          {user.type === "staff" && (
            <Button variant="outline" onClick={() => setConfirm("password")}>
              Send new temporary password
            </Button>
          )}
        </div>
      </CardBody>

      <ConfirmDialog
        open={confirm === "suspend"}
        onClose={() => setConfirm(null)}
        onConfirm={() => run("suspend")}
        loading={mutation.isPending}
        title={`Suspend ${user.name}?`}
        confirmLabel="Suspend"
      >
        They will be signed out everywhere and won&apos;t be able to sign in until reactivated.
      </ConfirmDialog>
      <ConfirmDialog
        open={confirm === "activate"}
        onClose={() => setConfirm(null)}
        onConfirm={() => run("activate")}
        loading={mutation.isPending}
        tone="primary"
        title={`Reactivate ${user.name}?`}
        confirmLabel="Reactivate"
      >
        They will be able to sign in again.
      </ConfirmDialog>
      <ConfirmDialog
        open={confirm === "password"}
        onClose={() => setConfirm(null)}
        onConfirm={() => run("password")}
        loading={mutation.isPending}
        tone="primary"
        title="Send a new temporary password?"
        confirmLabel="Send"
      >
        Their current password stops working immediately, all their sessions are signed out, and a temporary password is emailed to {user.email}.
      </ConfirmDialog>
    </Card>
  );
}
