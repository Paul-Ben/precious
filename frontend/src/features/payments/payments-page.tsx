"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Search } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Refund, RefundMethod } from "@/lib/api/types";
import { todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn, formatDateTime } from "@/lib/utils";
import { ATTENTION_LABELS, GATEWAY_NAMES, paymentKeys, paymentsApi } from "./api";
import { RefundStatusBadge, TransactionStatusBadge } from "./badges";

type Tab = "payments" | "refunds" | "cashup";

export function PaymentsPage() {
  return (
    <RequirePermission permission="payments.view">
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const [tab, setTab] = useState<Tab>("payments");
  const labels: Record<Tab, string> = { payments: "Payments", refunds: "Refunds", cashup: "Daily cash-up" };

  return (
    <>
      <PageHeader title="Payments" description="Online and desk payments, refunds and the daily cash-up." />
      <div role="tablist" aria-label="Payments sections" className="mb-4 inline-flex rounded-lg bg-surface-muted p-1">
        {(Object.keys(labels) as Tab[]).map((t) => (
          <button
            key={t}
            type="button"
            role="tab"
            aria-selected={tab === t}
            onClick={() => setTab(t)}
            className={cn("rounded-md px-4 py-2 text-sm font-medium", tab === t ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
          >
            {labels[t]}
          </button>
        ))}
      </div>
      {tab === "payments" ? <PaymentsList /> : tab === "refunds" ? <RefundsList /> : <CashUp />}
    </>
  );
}

// ---------------------------------------------------------------- Payments

function PaymentsList() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [method, setMethod] = useState("");
  const [attention, setAttention] = useState(false);
  const [page, setPage] = useState(1);
  const deferred = useDeferredValue(search);
  const filters = { search: deferred || undefined, status: status || undefined, method: method || undefined, attention: attention || undefined, page };

  const list = useQuery({ queryKey: paymentKeys.list(filters), queryFn: () => paymentsApi.list(filters), placeholderData: keepPreviousData });
  const reset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1); };

  return (
    <Card>
      <div className="flex flex-wrap items-end gap-3 border-b border-border p-4">
        <label className="relative min-w-60 flex-1">
          <span className="sr-only">Search payments</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
          <Input className="pl-9" placeholder="Reference, reservation, guest or email" value={search} onChange={(e) => reset(setSearch)(e.target.value)} />
        </label>
        <Select aria-label="Status" value={status} onChange={(e) => reset(setStatus)(e.target.value)} className="w-40">
          <option value="">All statuses</option>
          <option value="SUCCESSFUL">Successful</option>
          <option value="PENDING">Pending</option>
          <option value="FAILED">Failed</option>
          <option value="ABANDONED">Abandoned</option>
        </Select>
        <Select aria-label="Method" value={method} onChange={(e) => reset(setMethod)(e.target.value)} className="w-40">
          <option value="">All methods</option>
          <option value="GATEWAY">Online</option>
          <option value="CASH">Cash</option>
          <option value="POS">POS</option>
          <option value="BANK_TRANSFER">Bank transfer</option>
        </Select>
        <Checkbox label="Needs attention" checked={attention} onChange={(e) => reset(setAttention)(e.target.checked)} />
      </div>
      {list.isPending ? (
        <LoadingState />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.items.length === 0 ? (
        <EmptyState title="No payments found" />
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                <tr>
                  <th className="px-5 py-2 font-medium">When</th>
                  <th className="px-3 py-2 font-medium">Guest / reservation</th>
                  <th className="px-3 py-2 font-medium">Method</th>
                  <th className="px-3 py-2 text-right font-medium">Amount</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-5 py-2 font-medium">Receipt</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {list.data.items.map((p) => (
                  <tr key={p.id} className="hover:bg-surface-muted/60">
                    <td className="whitespace-nowrap px-5 py-2">
                      <Link href={`/staff/payments/${p.id}`} className="font-medium hover:underline">{formatDateTime(p.paid_at ?? p.created_at)}</Link>
                      <span className="block font-mono text-xs text-muted">{p.reference}</span>
                    </td>
                    <td className="px-3 py-2">
                      {p.guest?.full_name ?? "—"}
                      {p.payable && (
                        <Link href={`/staff/reservations/${p.payable.id}`} className="block font-mono text-xs text-muted hover:underline">{p.payable.number}</Link>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      {p.method === "GATEWAY" ? GATEWAY_NAMES[p.gateway ?? ""] ?? "Online" : p.method_label}
                      {p.gateway_mode === "test" && <span className="ml-1 text-xs text-warning">(test)</span>}
                    </td>
                    <td className="px-3 py-2 text-right">
                      {formatNaira(p.amount, { kobo: true })}
                      {Number(p.refunded_amount) > 0 && <span className="block text-xs text-danger">−{formatNaira(p.refunded_amount, { kobo: true })}</span>}
                    </td>
                    <td className="px-3 py-2">
                      <span className="inline-flex items-center gap-1">
                        <TransactionStatusBadge status={p.status} />
                        {p.needs_attention && <AlertTriangle className="size-4 text-warning" aria-label={ATTENTION_LABELS[p.attention_reason ?? ""] ?? "Needs attention"} />}
                      </span>
                    </td>
                    <td className="px-5 py-2">
                      {p.receipt_number ? <Link href={`/staff/receipts/${p.receipt_number}`} className="font-mono text-xs hover:underline">{p.receipt_number}</Link> : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={list.data.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

// ----------------------------------------------------------------- Refunds

function RefundsList() {
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [acting, setActing] = useState<{ refund: Refund; action: "reject" | "complete" } | null>(null);
  const filters = { status: status || undefined, page };
  const { can, user } = useSession();
  const queryClient = useQueryClient();
  const list = useQuery({ queryKey: paymentKeys.refunds(filters), queryFn: () => paymentsApi.refunds(filters), placeholderData: keepPreviousData });

  const approve = useMutation({
    mutationFn: (id: string) => paymentsApi.approveRefund(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["payments"] }),
  });

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
        <Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-52">
          <option value="">All refunds</option>
          <option value="REQUESTED">Awaiting approval</option>
          <option value="APPROVED">Approved - to pay out</option>
          <option value="COMPLETED">Refunded</option>
          <option value="REJECTED">Rejected</option>
        </Select>
        <p className="text-xs text-muted">Refunds are requested from a payment. Money goes back via the gateway dashboard, cash or transfer, then is recorded here.</p>
      </div>
      {approve.isError && <Alert tone="danger" className="m-4">{errorMessage(approve.error)}</Alert>}
      {list.isPending ? (
        <LoadingState />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.items.length === 0 ? (
        <EmptyState title="No refunds" />
      ) : (
        <>
          <ul className="divide-y divide-border">
            {list.data.items.map((r) => {
              const ownRequest = r.requested_by?.id === user.id;
              return (
                <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold">
                      {formatNaira(r.amount, { kobo: true })} <span className="font-mono text-xs font-normal text-muted">{r.number}</span>
                    </p>
                    <p className="text-xs text-muted">
                      {r.payment?.payable_number && (
                        <Link href={`/staff/reservations/${r.payment.payable_id}`} className="font-mono hover:underline">{r.payment.payable_number}</Link>
                      )}
                      {" · "}{r.payment?.method_label} · {r.reason}
                    </p>
                    <p className="text-xs text-muted">
                      Requested by {r.requested_by?.name ?? "—"} {formatDateTime(r.created_at)}
                      {r.approved_by && ` · approved by ${r.approved_by.name}`}
                      {r.completed_by && ` · paid out by ${r.completed_by.name} (${r.method?.replace("_", " ").toLowerCase()}${r.external_reference ? `, ${r.external_reference}` : ""})`}
                      {r.decision_note && ` · “${r.decision_note}”`}
                    </p>
                  </div>
                  <RefundStatusBadge status={r.status} />
                  {can("payments.refund") && r.status === "REQUESTED" && (
                    ownRequest ? (
                      <span className="text-xs text-muted">Needs another approver</span>
                    ) : (
                      <Button size="sm" loading={approve.isPending && approve.variables === r.id} onClick={() => approve.mutate(r.id)}>Approve</Button>
                    )
                  )}
                  {can("payments.refund") && r.status === "APPROVED" && (
                    <Button size="sm" onClick={() => setActing({ refund: r, action: "complete" })}>Mark paid out</Button>
                  )}
                  {can("payments.refund") && ["REQUESTED", "APPROVED"].includes(r.status) && (
                    <Button size="sm" variant="ghost" onClick={() => setActing({ refund: r, action: "reject" })}>Reject</Button>
                  )}
                </li>
              );
            })}
          </ul>
          <Pagination meta={list.data.meta} onPage={setPage} />
        </>
      )}
      {acting?.action === "reject" && <RejectRefundDialog refund={acting.refund} onClose={() => setActing(null)} />}
      {acting?.action === "complete" && <CompleteRefundDialog refund={acting.refund} onClose={() => setActing(null)} />}
    </Card>
  );
}

function RejectRefundDialog({ refund, onClose }: { refund: Refund; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [note, setNote] = useState("");
  const reject = useMutation({
    mutationFn: () => paymentsApi.rejectRefund(refund.id, note.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["payments"] });
      onClose();
    },
  });

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Reject refund ${refund.number}?`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Back</Button>
          <Button variant="danger" loading={reject.isPending} disabled={note.trim().length < 3} onClick={() => reject.mutate()}>Reject refund</Button>
        </>
      }
    >
      {reject.isError && <Alert tone="danger" className="mb-3">{errorMessage(reject.error)}</Alert>}
      <Field label="Reason" required>
        <Input value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
      </Field>
    </Dialog>
  );
}

function CompleteRefundDialog({ refund, onClose }: { refund: Refund; onClose: () => void }) {
  const queryClient = useQueryClient();
  const online = refund.payment?.method === "GATEWAY";
  const [method, setMethod] = useState<RefundMethod>(online ? "GATEWAY_DASHBOARD" : refund.payment?.method === "CASH" ? "CASH" : "BANK_TRANSFER");
  const [reference, setReference] = useState("");
  const complete = useMutation({
    mutationFn: () => paymentsApi.completeRefund(refund.id, method, reference.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["payments"] });
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      onClose();
    },
  });
  const err = isApiError(complete.error) ? complete.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Record refund ${refund.number} as paid out`}
      description={`${formatNaira(refund.amount, { kobo: true })} to the guest. Do this after the money has actually been sent.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Back</Button>
          <Button loading={complete.isPending} onClick={() => complete.mutate()}>Confirm refund paid</Button>
        </>
      }
    >
      <div className="space-y-4">
        {complete.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(complete.error)}</Alert>}
        {online && (
          <Alert tone="info">
            Issue the refund from the {GATEWAY_NAMES[refund.payment?.gateway ?? ""] ?? "gateway"} dashboard (transaction {refund.payment?.reference}), then record it here.
          </Alert>
        )}
        <Field label="How was it refunded?" required>
          <Select value={method} onChange={(e) => setMethod(e.target.value as RefundMethod)}>
            <option value="GATEWAY_DASHBOARD">Through the payment gateway</option>
            <option value="BANK_TRANSFER">Bank transfer</option>
            <option value="CASH">Cash</option>
          </Select>
        </Field>
        <Field label="Refund / transfer reference" required={method !== "CASH"} error={err?.field("external_reference")}>
          <Input value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />
        </Field>
      </div>
    </Dialog>
  );
}

// ----------------------------------------------------------------- Cash-up

function CashUp() {
  const [date, setDate] = useState(() => todayInHotel());
  const q = useQuery({ queryKey: paymentKeys.cashUp(date), queryFn: () => paymentsApi.cashUp(date) });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <Field label="Date">
          <Input type="date" value={date} max={todayInHotel()} onChange={(e) => e.target.value && setDate(e.target.value)} />
        </Field>
        <Button variant="outline" onClick={() => window.print()}>Print</Button>
      </div>
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <>
          {q.data.needs_attention > 0 && (
            <Alert tone="warning">{q.data.needs_attention} payment(s) need attention. Filter the Payments tab by “Needs attention”.</Alert>
          )}
          <div className="grid gap-4 sm:grid-cols-4">
            {[
              ["Received", q.data.received],
              ["Refunded", q.data.refunded],
              ["Net", q.data.net],
              ["Cash in drawer", q.data.cash_in_hand],
            ].map(([label, value]) => (
              <Card key={label}>
                <CardBody>
                  <p className="text-xs uppercase tracking-wider text-muted">{label}</p>
                  <p className="mt-1 text-2xl font-bold">{formatNaira(value, { kobo: true })}</p>
                </CardBody>
              </Card>
            ))}
          </div>
          <Card>
            <CardHeader title="By payment method" description="Successful payments received on this day (hotel time). Processing fees were paid by guests to the gateway." />
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                <tr>
                  <th className="px-5 py-2 font-medium">Method</th>
                  <th className="px-3 py-2 text-right font-medium">Payments</th>
                  <th className="px-5 py-2 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {q.data.by_method.map((m) => (
                  <tr key={m.method}>
                    <td className="px-5 py-2">{m.label}</td>
                    <td className="px-3 py-2 text-right">{m.count}</td>
                    <td className="px-5 py-2 text-right font-medium">{formatNaira(m.amount, { kobo: true })}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <CardBody className="text-xs text-muted">{q.data.refunds_count} refund(s) paid out on this day.</CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
