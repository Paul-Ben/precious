"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Printer } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { type FinanceSummary, financeApi, financeKeys, toKobo } from "./api";
import { Downloads } from "./downloads";
import { RangePicker, type RangeState, initialRange } from "./range-picker";

export function FinanceOverviewPage() {
  return (
    <RequirePermission permission={["finance.view", "finance.reports"]}>
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const { can, canAny } = useSession();
  const [range, setRange] = useState<RangeState>(() => initialRange("month"));
  const q = useQuery({ queryKey: financeKeys.summary(range.from, range.to), queryFn: () => financeApi.summary(range.from, range.to), placeholderData: keepPreviousData });

  return (
    <>
      <PageHeader
        title="Finance"
        description="Money received, expenses and net position. A cash view: revenue is money actually received, less refunds."
        actions={
          <Button variant="outline" className="print:hidden" onClick={() => window.print()}>
            <Printer className="size-4" aria-hidden /> Print
          </Button>
        }
      />
      <div className="mb-4 print:hidden">
        <RangePicker value={range} onChange={setRange} />
      </div>
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <div className={cn("space-y-4 transition-opacity", q.isPlaceholderData && "opacity-60")}>
          <Report
            data={q.data}
            canExport={can("finance.reports")}
            canSeeExpenses={canAny(["finance.expenses", "finance.view", "finance.expenses.approve"])}
            canSeeDays={canAny(["finance.close_day", "finance.view"])}
          />
        </div>
      )}
    </>
  );
}

function Report({ data, canExport, canSeeExpenses, canSeeDays }: { data: FinanceSummary; canExport: boolean; canSeeExpenses: boolean; canSeeDays: boolean }) {
  const { revenue, expenses } = data;
  const net = toKobo(data.net_position);

  return (
    <>
      <p className="text-sm text-muted">
        {formatStayDate(data.from, true)} – {formatStayDate(data.to, true)}
      </p>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Revenue (received)" value={formatNaira(revenue.net)} hint={`${formatNaira(revenue.received)} in · ${formatNaira(revenue.refunded)} refunded`} />
        <Kpi
          label="Expenses"
          value={formatNaira(expenses.total)}
          hint={expenses.pending_count > 0 ? `${expenses.pending_count} waiting for approval (${formatNaira(expenses.pending_amount)})` : "All approved"}
          href={canSeeExpenses ? "/staff/finance/expenses" : undefined}
        />
        <Kpi label="Net position" value={formatNaira(data.net_position)} tone={net < 0 ? "danger" : "success"} hint="Revenue less approved expenses" />
        <Kpi
          label="Outstanding now"
          value={formatNaira(data.outstanding.total)}
          hint={`${data.outstanding.count} bill${data.outstanding.count === 1 ? "" : "s"} · hotel ${formatNaira(data.outstanding.reservations)} · bar ${formatNaira(data.outstanding.bar_tabs)}`}
          href="/staff/finance/outstanding"
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Money received" description="Successful payments. Gateway fees paid by guests are excluded." />
          <Rows
            rows={[
              ["Hotel", formatNaira(revenue.hotel)],
              ["Bar", formatNaira(revenue.bar)],
              ...revenue.by_method.filter((m) => toKobo(m.amount) !== 0).map((m) => [`  ${m.label} (${m.count})`, formatNaira(m.amount)] as [string, string]),
              ["Refunded", `−${formatNaira(revenue.refunded)}`],
              ["Revenue", formatNaira(revenue.net)],
            ]}
          />
          <CardBody className="border-t border-border text-xs text-muted">
            Billed in the period (earned, whether paid or not): rooms {formatNaira(revenue.billed.rooms)} · hotel services {formatNaira(revenue.billed.services)} · bar {formatNaira(revenue.billed.bar)}.
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Expenses by category" description="Approved expenses, by expense date." />
          {expenses.by_category.length === 0 ? (
            <CardBody className="text-sm text-muted">No approved expenses in this period.</CardBody>
          ) : (
            <Rows rows={expenses.by_category.map((c) => [`${c.category} (${c.count})`, formatNaira(c.amount)] as [string, string])} />
          )}
        </Card>
      </div>

      <Card>
        <CardHeader title="By day" description="Closed days are locked: corrections go into an open day." />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
              <tr>
                <th scope="col" className="px-5 py-2 font-medium">Date</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Received</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Refunded</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Expenses</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">Net</th>
                <th scope="col" className="px-5 py-2 font-medium">Day</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {[...data.days].reverse().map((d) => (
                <tr key={d.date}>
                  <td className="px-5 py-2">
                    {canSeeDays ? (
                      <Link href={`/staff/finance/closing?date=${d.date}`} className="hover:underline">{formatStayDate(d.date)}</Link>
                    ) : (
                      formatStayDate(d.date)
                    )}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatNaira(d.received)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatNaira(d.refunded)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatNaira(d.expenses)}</td>
                  <td className={cn("px-3 py-2 text-right font-medium tabular-nums", toKobo(d.net) < 0 && "text-danger")}>{formatNaira(d.net)}</td>
                  <td className="px-5 py-2">
                    {d.closing?.status === "CLOSED" ? (
                      <Badge tone={toKobo(d.closing.cash_difference) === 0 ? "success" : "warning"}>
                        Closed{toKobo(d.closing.cash_difference) !== 0 ? ` · ${formatNaira(d.closing.cash_difference)}` : ""}
                      </Badge>
                    ) : d.closing?.status === "REOPENED" ? (
                      <Badge tone="info">Reopened</Badge>
                    ) : (
                      <Badge>Open</Badge>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {canExport && (
        <Card className="print:hidden">
          <CardHeader title="Downloads" description="For this period. Outstanding bills are as of now." />
          <CardBody className="grid gap-3 sm:grid-cols-2">
            <Downloads type="summary" label="Daily summary" from={data.from} to={data.to} />
            <Downloads type="expenses" label="Expenses" from={data.from} to={data.to} />
            <Downloads type="refunds" label="Refunds" from={data.from} to={data.to} />
            <Downloads type="outstanding" label="Outstanding bills" />
          </CardBody>
        </Card>
      )}
    </>
  );
}

function Kpi({ label, value, hint, tone, href }: { label: string; value: string; hint: string; tone?: "success" | "danger"; href?: string }) {
  const body = (
    <CardBody>
      <p className="text-xs uppercase tracking-wider text-muted">{label}</p>
      <p className={cn("mt-1 text-2xl font-bold tabular-nums", tone === "danger" && "text-danger", tone === "success" && "text-success")}>{value}</p>
      <p className="mt-1 text-xs text-muted">{hint}</p>
    </CardBody>
  );
  return <Card>{href ? <Link href={href} className="block rounded-xl hover:bg-surface-muted/50">{body}</Link> : body}</Card>;
}

function Rows({ rows }: { rows: [string, string][] }) {
  return (
    <dl className="divide-y divide-border text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-4 px-5 py-2">
          <dt className={cn(k.startsWith("  ") ? "pl-4 text-muted" : "text-muted")}>{k.trim()}</dt>
          <dd className="font-medium tabular-nums">{v}</dd>
        </div>
      ))}
    </dl>
  );
}
