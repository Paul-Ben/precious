"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronDown } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useDeferredValue, useState } from "react";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { api } from "@/lib/api/client";
import type { AuditLog } from "@/lib/api/types";
import { formatDateTime } from "@/lib/utils";

export function AuditLogsPage() {
  return (
    <RequirePermission permission="audit.view">
      <AuditLogList />
    </RequirePermission>
  );
}

function AuditLogList() {
  const params = useSearchParams();
  const [action, setAction] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const deferredAction = useDeferredValue(action);

  const query = {
    action: deferredAction,
    from,
    to,
    page,
    auditable_type: params.get("auditable_type") ?? "",
    auditable_id: params.get("auditable_id") ?? "",
  };

  const logs = useQuery({
    queryKey: ["audit-logs", query],
    queryFn: ({ signal }) => api.list<AuditLog>("audit-logs", query, signal),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <PageHeader title="Audit log" description="An append-only record of sensitive actions. Entries cannot be edited or deleted." />
      <Card>
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Action starts with</span>
            <Input placeholder="e.g. auth. or users." value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">From</span>
            <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">To</span>
            <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
          </label>
        </div>

        {logs.isPending ? (
          <LoadingState />
        ) : logs.isError ? (
          <ErrorState error={logs.error} onRetry={() => logs.refetch()} />
        ) : logs.data.items.length === 0 ? (
          <EmptyState title="No audit entries match these filters" />
        ) : (
          <>
            <ul className="divide-y divide-border">
              {logs.data.items.map((log) => (
                <AuditRow key={log.id} log={log} />
              ))}
            </ul>
            <Pagination meta={logs.data.meta} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}

function AuditRow({ log }: { log: AuditLog }) {
  const [open, setOpen] = useState(false);
  const hasDetail = log.old_values || log.new_values || log.metadata;

  return (
    <li className="px-5 py-3 text-sm">
      <button
        type="button"
        className="flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-left"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        disabled={!hasDetail}
      >
        <code className="font-mono text-xs font-semibold">{log.action}</code>
        <span className="text-muted">{log.actor ? log.actor.name : "System / guest"}</span>
        {log.auditable_type && (
          <span className="text-xs text-muted">
            {log.auditable_type} {log.auditable_id?.slice(0, 8)}
          </span>
        )}
        <span className="ml-auto text-xs text-muted">{formatDateTime(log.created_at)}</span>
        {hasDetail && <ChevronDown className={`size-4 text-muted transition ${open ? "rotate-180" : ""}`} aria-hidden="true" />}
      </button>
      {open && (
        <div className="mt-3 grid gap-3 rounded-lg bg-surface-muted p-3 text-xs md:grid-cols-3">
          {(["old_values", "new_values", "metadata"] as const).map((key) => (
            <div key={key}>
              <p className="mb-1 font-medium text-muted">{key === "old_values" ? "Before" : key === "new_values" ? "After" : "Details"}</p>
              <pre className="overflow-x-auto whitespace-pre-wrap break-all font-mono">{log[key] ? JSON.stringify(log[key], null, 2) : "—"}</pre>
            </div>
          ))}
          <p className="text-muted md:col-span-3">
            IP {log.ip_address ?? "—"} · Request {log.request_id ?? "—"}
          </p>
        </div>
      )}
    </li>
  );
}
