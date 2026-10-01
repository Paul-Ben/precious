"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, ChevronLeft, ChevronRight, Lock, Printer } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import { addDays, formatStayDate, isValidDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn, formatDateTime } from "@/lib/utils";
import { EXPENSE_METHODS, EXPENSE_STATUS, type FinanceDay, financeApi, financeKeys, fromKobo, parseAmount, toKobo } from "./api";

export function ClosingPage({ initialDate }: { initialDate?: string }) {
  return (
    <RequirePermission permission={["finance.close_day", "finance.view"]}>
      <Inner initialDate={initialDate} />
    </RequirePermission>
  );
}

function Inner({ initialDate }: { initialDate?: string }) {
  const today = todayInHotel();
  const [date, setDate] = useState(() => (initialDate && isValidDate(initialDate) && initialDate <= today ? initialDate : today));
  const q = useQuery({ queryKey: financeKeys.day(date), queryFn: () => financeApi.day(date) });

  return (
    <>
      <PageHeader
        title="Daily closing"
        description="Count the cash at the end of the day and close it. A closed day's desk payments and cash expenses are locked."
        actions={
          <Button variant="outline" className="print:hidden" onClick={() => window.print()}>
            <Printer className="size-4" aria-hidden /> Print
          </Button>
        }
      />
      <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        <Button variant="outline" size="sm" aria-label="Previous day" onClick={() => setDate(addDays(date, -1))}>
          <ChevronLeft className="size-4" aria-hidden />
        </Button>
        <Input type="date" className="h-9 w-auto" aria-label="Day" value={date} max={today} onChange={(e) => e.target.value && e.target.value <= today && setDate(e.target.value)} />
        <Button variant="outline" size="sm" aria-label="Next day" disabled={date >= today} onClick={() => setDate(addDays(date, 1))}>
          <ChevronRight className="size-4" aria-hidden />
        </Button>
        {date !== today && <Button variant="ghost" size="sm" onClick={() => setDate(today)}>Today</Button>}
      </div>
      {q.isPending ? <LoadingState /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : <Day key={date} day={q.data} />}
    </>
  );
}

function Day({ day }: { day: FinanceDay }) {
  const { can } = useSession();
  const closed = day.status === "CLOSED";

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-semibold">{formatStayDate(day.date, true)}</h2>
        {closed ? (
          <Badge tone="success"><Lock className="size-3" aria-hidden /> Closed</Badge>
        ) : day.status === "REOPENED" ? (
          <Badge tone="info">Reopened</Badge>
        ) : (
          <Badge>Open</Badge>
        )}
      </div>

      {day.needs_attention > 0 && (
        <Alert tone="warning">{day.needs_attention} online payment(s) need attention in Payments before you close.</Alert>
      )}
      {day.expenses.pending_count > 0 && (
        <Alert tone="info">
          {day.expenses.pending_count} expense(s) on this day are waiting for approval. Cash ones are already counted as paid out of the drawer.
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Money received" description="Successful payments on this day (hotel time)." />
          <Rows
            rows={[
              ...day.by_method.map((m) => [`${m.label} (${m.count})`, formatNaira(m.amount)] as [string, string]),
              ["Total received", formatNaira(day.received)],
              ["  of which hotel", formatNaira(day.hotel)],
              ["  of which bar", formatNaira(day.bar)],
              [`Refunds paid (${day.refunds.count})`, formatNaira(day.refunds.total)],
              ["Approved expenses", formatNaira(day.expenses.approved)],
            ]}
          />
        </Card>
        <Card>
          <CardHeader title="Cash drawer" description="What should be in the cash box from today's activity." />
          <Rows
            rows={[
              ["Cash received", formatNaira(day.cash.received)],
              ["Cash refunds", `−${formatNaira(day.cash.refunded)}`],
              ["Cash expenses", `−${formatNaira(day.cash.expenses)}`],
              ["Expected cash", formatNaira(day.cash.expected)],
            ]}
            strong="Expected cash"
          />
          {day.closing && <ClosingRecord day={day} />}
        </Card>
      </div>

      {day.expenses.lines.length > 0 && (
        <Card>
          <CardHeader title="Expenses on this day" />
          <ul className="divide-y divide-border text-sm">
            {day.expenses.lines.map((l) => (
              <li key={l.number} className="flex flex-wrap items-center gap-3 px-5 py-2">
                <span className="min-w-0 flex-1">
                  {l.description}
                  <span className="block text-xs text-muted">{l.number} · {l.category ?? "—"} · {EXPENSE_METHODS.find((m) => m.value === l.method)?.label ?? l.method}</span>
                </span>
                {l.status === "PENDING" && <Badge tone={EXPENSE_STATUS.PENDING.tone}>Pending</Badge>}
                <span className="font-medium tabular-nums">{formatNaira(l.amount)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {!closed && can("finance.close_day") && <CloseForm day={day} />}
      {closed && can("finance.reopen_day") && <ReopenForm day={day} />}

      <p className="hidden pt-8 text-sm print:block">Counted by: ______________________ &nbsp;&nbsp; Checked by: ______________________</p>
    </div>
  );
}

function ClosingRecord({ day }: { day: FinanceDay }) {
  const c = day.closing!;
  const diff = toKobo(c.cash_difference);
  return (
    <CardBody className="space-y-1 border-t border-border text-sm">
      <p className="flex items-center gap-2 font-medium">
        <CheckCircle2 className={cn("size-4", diff === 0 ? "text-success" : "text-warning")} aria-hidden />
        Counted {formatNaira(c.cash_counted)} against {formatNaira(c.cash_expected)} expected
        {diff === 0 ? " — matches." : ` — ${diff > 0 ? "over" : "short"} by ${formatNaira(fromKobo(Math.abs(diff)))}.`}
      </p>
      {c.note && <p className="text-muted">Note: {c.note}</p>}
      <p className="text-xs text-muted">Closed by {c.closed_by ?? "—"} · {formatDateTime(c.closed_at)}</p>
      {c.reopened_at && (
        <p className="text-xs text-muted">
          Reopened by {c.reopened_by ?? "—"} · {formatDateTime(c.reopened_at)} — {c.reopen_reason}
        </p>
      )}
    </CardBody>
  );
}

function CloseForm({ day }: { day: FinanceDay }) {
  const queryClient = useQueryClient();
  const [counted, setCounted] = useState("");
  const [note, setNote] = useState("");
  const amount = parseAmount(counted);
  const diff = amount !== null ? toKobo(amount) - toKobo(day.cash.expected) : null;
  const close = useMutation({
    mutationFn: () => financeApi.close(day.date, amount!, note.trim()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["finance"] }),
  });

  return (
    <Card className="print:hidden">
      <CardHeader title="Close the day" description="Count the cash in the drawer and enter the total." />
      <form
        className="grid gap-4 p-5 sm:grid-cols-[14rem_1fr]"
        onSubmit={(e) => {
          e.preventDefault();
          close.mutate();
        }}
      >
        <Field label="Cash counted (₦)" required>
          <Input inputMode="decimal" value={counted} placeholder="0.00" onChange={(e) => setCounted(e.target.value)} />
        </Field>
        <Field
          label="Note"
          required={diff !== null && diff !== 0}
          hint={diff === null ? undefined : diff === 0 ? "Matches the expected cash." : `${diff > 0 ? "Over" : "Short"} by ${formatNaira(fromKobo(Math.abs(diff)))} — explain the difference.`}
        >
          <Input value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
        </Field>
        {close.isError && <Alert tone="danger" className="sm:col-span-2">{errorMessage(close.error)}</Alert>}
        <div className="flex justify-end sm:col-span-2">
          <Button type="submit" loading={close.isPending} disabled={amount === null || (diff !== 0 && note.trim().length === 0)}>
            <Lock className="size-4" aria-hidden /> Close {formatStayDate(day.date)}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ReopenForm({ day }: { day: FinanceDay }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const reopen = useMutation({
    mutationFn: () => financeApi.reopen(day.date, reason.trim()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["finance"] }),
  });

  if (!open) {
    return (
      <div className="flex justify-end print:hidden">
        <Button variant="outline" onClick={() => setOpen(true)}>Reopen this day</Button>
      </div>
    );
  }

  return (
    <Card className="print:hidden">
      <CardHeader title="Reopen the day" description="Unlocks the day's desk payments and cash expenses. It must be closed again afterwards." />
      <div className="space-y-3 p-5">
        <Field label="Reason" required>
          <Input value={reason} minLength={3} maxLength={255} onChange={(e) => setReason(e.target.value)} />
        </Field>
        {reopen.isError && <Alert tone="danger">{errorMessage(reopen.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={reopen.isPending} disabled={reason.trim().length < 3} onClick={() => reopen.mutate()}>Reopen</Button>
        </div>
      </div>
    </Card>
  );
}

function Rows({ rows, strong }: { rows: [string, string][]; strong?: string }) {
  return (
    <dl className="divide-y divide-border text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className={cn("flex justify-between gap-4 px-5 py-2", k === strong && "bg-surface-muted/60")}>
          <dt className={cn("text-muted", k.startsWith("  ") && "pl-4", k === strong && "font-semibold text-foreground")}>{k.trim()}</dt>
          <dd className={cn("font-medium tabular-nums", k === strong && "text-base font-bold")}>{v}</dd>
        </div>
      ))}
    </dl>
  );
}
