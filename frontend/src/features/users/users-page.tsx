"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatDateTime } from "@/lib/utils";
import { rolesApi, userKeys, usersApi, type UserFilters } from "./api";
import { CreateStaffDialog } from "./create-staff-dialog";

export function UsersPage() {
  return (
    <RequirePermission permission="users.view">
      <UsersList />
    </RequirePermission>
  );
}

function UsersList() {
  const { can } = useSession();
  const [filters, setFilters] = useState<UserFilters>({ type: "staff", status: "", search: "", role: "", page: 1 });
  const deferredSearch = useDeferredValue(filters.search);
  const [creating, setCreating] = useState(false);

  const query = { ...filters, search: deferredSearch };
  const users = useQuery({
    queryKey: userKeys.list(query),
    queryFn: ({ signal }) => usersApi.list(query, signal),
    placeholderData: keepPreviousData,
  });
  const roles = useQuery({ queryKey: ["roles"], queryFn: rolesApi.list, enabled: can("roles.view") || can("users.create") });

  const update = (patch: Partial<UserFilters>) => setFilters((f) => ({ ...f, page: 1, ...patch }));

  return (
    <>
      <PageHeader
        title="Users"
        description="Staff accounts and customer accounts."
        actions={
          can("users.create") && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden="true" /> Add staff member
            </Button>
          )
        }
      />

      <Card>
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_auto_auto_auto]">
          <label className="relative">
            <span className="sr-only">Search users</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <Input
              className="pl-9"
              placeholder="Search name, email or phone"
              value={filters.search}
              onChange={(e) => update({ search: e.target.value })}
            />
          </label>
          <Select aria-label="Account type" value={filters.type} onChange={(e) => update({ type: e.target.value as UserFilters["type"] })}>
            <option value="">All types</option>
            <option value="staff">Staff</option>
            <option value="customer">Customers</option>
          </Select>
          <Select aria-label="Status" value={filters.status} onChange={(e) => update({ status: e.target.value as UserFilters["status"] })}>
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </Select>
          <Select aria-label="Role" value={filters.role} onChange={(e) => update({ role: e.target.value })}>
            <option value="">Any role</option>
            {roles.data?.map((r) => (
              <option key={r.id} value={r.name}>
                {r.name}
              </option>
            ))}
          </Select>
        </div>

        {users.isPending ? (
          <LoadingState label="Loading users…" />
        ) : users.isError ? (
          <ErrorState error={users.error} onRetry={() => users.refetch()} />
        ) : users.data.items.length === 0 ? (
          <EmptyState title="No users found">Try a different search or filter.</EmptyState>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-3 font-medium">Name</th>
                    <th scope="col" className="px-5 py-3 font-medium">Roles</th>
                    <th scope="col" className="px-5 py-3 font-medium">Status</th>
                    <th scope="col" className="px-5 py-3 font-medium">Last sign-in</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {users.data.items.map((u) => (
                    <tr key={u.id} className="hover:bg-surface-muted/60">
                      <td className="px-5 py-3">
                        <Link href={`/staff/users/${u.id}`} className="font-medium hover:underline">
                          {u.name}
                        </Link>
                        <p className="text-xs text-muted">{u.email}</p>
                      </td>
                      <td className="px-5 py-3">
                        <div className="flex flex-wrap gap-1">
                          {u.roles.map((r) => (
                            <Badge key={r}>{r}</Badge>
                          ))}
                        </div>
                      </td>
                      <td className="px-5 py-3">
                        <Badge tone={u.status === "active" ? "success" : "danger"}>
                          {u.status === "active" ? "Active" : "Suspended"}
                        </Badge>
                        {u.must_change_password && (
                          <Badge tone="warning" className="ml-1">
                            Temp password
                          </Badge>
                        )}
                      </td>
                      <td className="px-5 py-3 text-muted">{formatDateTime(u.last_login_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={users.data.meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
          </>
        )}
      </Card>

      {can("users.create") && (
        <CreateStaffDialog open={creating} onClose={() => setCreating(false)} roles={roles.data ?? []} />
      )}
    </>
  );
}
