"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Card, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { financeApi, financeKeys } from "./api";
import { Downloads } from "./downloads";

/** Guests and bar customers who owe money right now (P34). */
export function OutstandingPage() {
  return (
    <RequirePermission permission={["finance.view", "finance.reports"]}>
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const { can } = useSession();
  const q = useQuery({ queryKey: financeKeys.outstanding, queryFn: financeApi.outstanding, refetchInterval: 60_000 });

  return (
    <>
      <PageHeader
        title="Outstanding bills"
        description="Checked-in and checked-out guests with a balance, and open bar bills with money owed."
        actions={can("finance.reports") && <Downloads type="outstanding" label="Download" />}
      />
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <div className="space-y-4">
          <p className="text-lg">
            Total owed: <strong className="tabular-nums">{formatNaira(q.data.total)}</strong>
          </p>
          <Card>
            <CardHeader title={`Hotel guests (${q.data.reservations.length})`} />
            {q.data.reservations.length === 0 ? (
              <p className="px-5 py-4 text-sm text-muted">Nobody owes the hotel.</p>
            ) : (
              <Table
                head={["Reservation", "Guest", "Stay", "Total", "Paid", "Balance"]}
                rows={q.data.reservations.map((r) => [
                  can("reservations.view") ? <Link key="l" href={`/staff/reservations/${r.id}`} className="font-medium hover:underline">{r.number}</Link> : r.number,
                  r.guest ?? "—",
                  `${formatStayDate(r.check_in)} – ${formatStayDate(r.check_out)}${r.status === "CHECKED_OUT" ? " (left)" : ""}`,
                  formatNaira(r.total),
                  formatNaira(r.paid),
                  formatNaira(r.balance),
                ])}
              />
            )}
          </Card>
          <Card>
            <CardHeader title={`Open bar bills (${q.data.bar_tabs.length})`} />
            {q.data.bar_tabs.length === 0 ? (
              <p className="px-5 py-4 text-sm text-muted">No open bar bills with money owed.</p>
            ) : (
              <Table
                head={["Bill", "Customer", "Table · waiter", "Total", "Paid", "Balance"]}
                rows={q.data.bar_tabs.map((t) => [
                  <span key="n">{t.number}<span className="block text-xs text-muted">Opened {formatDateTime(t.opened_at)}</span></span>,
                  t.customer ?? "Walk-in",
                  [t.table ?? "No table", t.waiter].filter(Boolean).join(" · "),
                  formatNaira(t.total),
                  formatNaira(t.paid),
                  formatNaira(t.balance),
                ])}
              />
            )}
          </Card>
        </div>
      )}
    </>
  );
}

function Table({ head, rows }: { head: string[]; rows: React.ReactNode[][] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[680px] text-left text-sm">
        <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
          <tr>
            {head.map((h, i) => (
              <th key={h} scope="col" className={i >= 3 ? "px-3 py-2 text-right font-medium last:pr-5" : "px-5 py-2 font-medium first:pl-5"}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((r, ri) => (
            <tr key={ri}>
              {r.map((c, i) => (
                <td key={i} className={i >= 3 ? `px-3 py-2 text-right tabular-nums last:pr-5 ${i === r.length - 1 ? "font-semibold" : ""}` : "px-5 py-2"}>{c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
