"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Search } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatStayDate } from "@/lib/dates";
import { EMPLOYMENT_LABELS, type EmploymentStatus, type StaffFilters, teamApi, teamKeys } from "./api";
import { StaffAvatar } from "./staff-avatar";

export function TeamPage() {
  return (
    <RequirePermission permission="staff.view">
      <Inner />
    </RequirePermission>
  );
}

const STATUS_TONE = { ACTIVE: "success", ON_LEAVE: "warning", LEFT: "neutral" } as const;

function Inner() {
  const { can } = useSession();
  const [filters, setFilters] = useState<StaffFilters>({ search: "", department_id: "", employment_status: "", page: 1 });
  const search = useDeferredValue(filters.search);
  const query = { ...filters, search };
  const list = useQuery({ queryKey: teamKeys.list(query), queryFn: ({ signal }) => teamApi.list(query, signal), placeholderData: keepPreviousData });
  const departments = useQuery({ queryKey: teamKeys.departments, queryFn: teamApi.departments });
  const update = (patch: Partial<StaffFilters>) => setFilters((f) => ({ ...f, page: 1, ...patch }));

  return (
    <>
      <PageHeader
        title="Staff"
        description={
          can("users.create")
            ? "Employee records. New people are added in Users; they appear here with an employee number."
            : "Employee records."
        }
      />
      <Card>
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_auto_auto]">
          <label className="relative">
            <span className="sr-only">Search staff</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
            <Input className="pl-9" placeholder="Name, email, employee no. or position" value={filters.search} onChange={(e) => update({ search: e.target.value })} />
          </label>
          <Select aria-label="Department" value={filters.department_id} onChange={(e) => update({ department_id: e.target.value ? Number(e.target.value) : "" })}>
            <option value="">All departments</option>
            {departments.data?.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </Select>
          <Select
            aria-label="Employment status"
            value={filters.employment_status}
            onChange={(e) => update({ employment_status: e.target.value as EmploymentStatus | "" })}
          >
            <option value="">Any status</option>
            {(Object.keys(EMPLOYMENT_LABELS) as EmploymentStatus[]).map((s) => (
              <option key={s} value={s}>{EMPLOYMENT_LABELS[s]}</option>
            ))}
          </Select>
        </div>

        {list.isPending ? (
          <LoadingState label="Loading staff…" />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title="No staff found">Try a different search or filter.</EmptyState>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[680px] text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-3 font-medium">Name</th>
                    <th scope="col" className="px-3 py-3 font-medium">Employee no.</th>
                    <th scope="col" className="px-3 py-3 font-medium">Department · position</th>
                    <th scope="col" className="px-3 py-3 font-medium">Roles</th>
                    <th scope="col" className="px-5 py-3 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {list.data.items.map((m) => (
                    <tr key={m.id} className="hover:bg-surface-muted/60">
                      <td className="px-5 py-3">
                        <Link href={`/staff/team/${m.id}`} className="flex items-center gap-3">
                          <StaffAvatar member={m} size="sm" />
                          <span>
                            <span className="font-medium hover:underline">{m.name}</span>
                            <span className="block text-xs text-muted">{m.email}</span>
                          </span>
                        </Link>
                      </td>
                      <td className="px-3 py-3 font-mono text-xs">{m.employee_number}</td>
                      <td className="px-3 py-3">
                        {m.department?.name ?? <span className="text-muted">No department</span>}
                        {m.position && <span className="block text-xs text-muted">{m.position}</span>}
                      </td>
                      <td className="px-3 py-3">
                        <div className="flex flex-wrap gap-1">
                          {m.roles.map((r) => (
                            <Badge key={r}>{r}</Badge>
                          ))}
                        </div>
                      </td>
                      <td className="px-5 py-3">
                        <Badge tone={STATUS_TONE[m.employment_status]}>{m.employment_status_label}</Badge>
                        {m.start_date && <span className="mt-1 block text-xs text-muted">Since {formatStayDate(m.start_date, true)}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={list.data.meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
          </>
        )}
      </Card>
    </>
  );
}
