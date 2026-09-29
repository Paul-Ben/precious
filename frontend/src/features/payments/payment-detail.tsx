"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, ArrowLeft, RefreshCw } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { Payment } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { ATTENTION_LABELS, GATEWAY_NAMES, paymentKeys, paymentsApi } from "./api";
import { RefundStatusBadge, TransactionStatusBadge } from "./badges";
import { RequestRefundDialog } from "./reservation-payments";

export function PaymentDetail({ id }: { id: string }) {
  return (
    <RequirePermission permission="payments.view">
      <Inner id={id} />
    </RequirePermission>
  );
}

function Inner({ id }: { id: string }) {
  const q = useQuery({ queryKey: paymentKeys.payment(id), queryFn: () => paymentsApi.payment(id) });

  return (
    <>
      <Link href="/staff/payments" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> Payments
      </Link>
      {q.isPending ? <LoadingState /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : <Loaded p={q.data} />}
    </>
  );
}

function Loaded({ p }: { p: Payment }) {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const [refunding, setRefunding] = useState(false);
  const [resolving, setResolving] = useState(false);

  const recheck = useMutation({
    mutationFn: () => paymentsApi.recheck(p.id),
    onSuccess: (res) => {
      queryClient.setQueryData(paymentKeys.payment(p.id), res.data);
      void queryClient.invalidateQueries({ queryKey: ["payments", "list"] });
    },
  });

  const rows: [string, React.ReactNode][] = [
    ["Reference", <span key="r" className="font-mono">{p.reference}</span>],
    ["Method", `${p.method === "GATEWAY" ? `${GATEWAY_NAMES[p.gateway ?? ""] ?? "Online"}${p.gateway_mode === "test" ? " (test mode)" : ""}` : p.method_label}${p.channel ? ` · ${p.channel.replace("_", " ")}` : ""}`],
    ["Purpose", p.purpose.toLowerCase()],
    ["Amount credited", formatNaira(p.amount, { kobo: true })],
    ["Processing fee (paid by guest)", formatNaira(p.customer_fee, { kobo: true })],
    ["Total charged", formatNaira(p.charged_amount, { kobo: true })],
    ["Gateway fee deducted", p.gateway_fee ? formatNaira(p.gateway_fee, { kobo: true }) : "—"],
    ["Refunded", formatNaira(p.refunded_amount, { kobo: true })],
    ["Paid at", formatDateTime(p.paid_at)],
    ["Started", formatDateTime(p.created_at)],
    ["Gateway transaction", p.gateway_transaction_id ?? "—"],
    ["Slip / transfer reference", p.external_reference ?? "—"],
    ["Payer email", p.payer_email ?? "—"],
    ["Recorded by", p.recorded_by?.name ?? (p.method === "GATEWAY" ? "Online" : "—")],
    ["Note", p.note ?? "—"],
  ];

  return (
    <>
      <PageHeader
        title={formatNaira(p.amount, { kobo: true })}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <TransactionStatusBadge status={p.status} />
            {p.guest && <Link href={`/staff/guests/${p.guest.id}`} className="hover:underline">{p.guest.full_name}</Link>}
            {p.payable && <Link href={`/staff/reservations/${p.payable.id}`} className="font-mono hover:underline">{p.payable.number}</Link>}
          </span>
        }
        actions={
          <>
            {p.method === "GATEWAY" && p.status === "PENDING" && (
              <Button variant="outline" loading={recheck.isPending} onClick={() => recheck.mutate()}>
                <RefreshCw className="size-4" aria-hidden="true" /> Check with gateway
              </Button>
            )}
            {p.receipt_number && <Link href={`/staff/receipts/${p.receipt_number}`} className="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm font-medium hover:bg-surface-muted">Receipt {p.receipt_number}</Link>}
            {can("payments.refund") && p.status === "SUCCESSFUL" && Number(p.refundable ?? 0) > 0 && (
              <Button variant="danger" onClick={() => setRefunding(true)}>Refund</Button>
            )}
          </>
        }
      />

      {recheck.isSuccess && <Alert tone="info" className="mb-4">{recheck.data.message}</Alert>}
      {recheck.isError && <Alert tone="danger" className="mb-4">{errorMessage(recheck.error)}</Alert>}
      {p.failure_reason && <Alert tone="danger" className="mb-4" title="Failure reason">{p.failure_reason}</Alert>}
      {p.needs_attention && (
        <Alert tone="warning" className="mb-4" title="Needs attention">
          <span className="flex flex-wrap items-center gap-3">
            <span className="inline-flex items-center gap-1"><AlertTriangle className="size-4" aria-hidden="true" /> {ATTENTION_LABELS[p.attention_reason ?? ""] ?? p.attention_reason}</span>
            {can("payments.refund") && <Button size="sm" variant="outline" onClick={() => setResolving(true)}>Mark resolved</Button>}
          </span>
        </Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
        <Card>
          <CardBody>
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              {rows.map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs uppercase tracking-wider text-muted">{label}</dt>
                  <dd className="mt-0.5">{value}</dd>
                </div>
              ))}
            </dl>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Refunds" />
          {p.refunds && p.refunds.length > 0 ? (
            <ul className="divide-y divide-border text-sm">
              {p.refunds.map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-2 px-5 py-2">
                  <span><span className="font-mono text-xs">{r.number}</span> · {formatNaira(r.amount, { kobo: true })}</span>
                  <RefundStatusBadge status={r.status} />
                </li>
              ))}
            </ul>
          ) : (
            <CardBody className="text-sm text-muted">No refunds.</CardBody>
          )}
        </Card>
      </div>

      {refunding && <RequestRefundDialog payment={p} onClose={() => { setRefunding(false); void queryClient.invalidateQueries({ queryKey: paymentKeys.payment(p.id) }); }} />}
      {resolving && <ResolveDialog p={p} onClose={() => setResolving(false)} />}
    </>
  );
}

function ResolveDialog({ p, onClose }: { p: Payment; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [note, setNote] = useState("");
  const resolve = useMutation({
    mutationFn: () => paymentsApi.resolve(p.id, note.trim()),
    onSuccess: (res) => {
      queryClient.setQueryData(paymentKeys.payment(p.id), res.data);
      onClose();
    },
  });

  return (
    <Dialog
      open
      onClose={onClose}
      title="Mark as resolved"
      description="Record what was done (e.g. guest moved to another room, or refund issued)."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={resolve.isPending} disabled={note.trim().length < 5} onClick={() => resolve.mutate()}>Mark resolved</Button>
        </>
      }
    >
      {resolve.isError && <Alert tone="danger" className="mb-3">{errorMessage(resolve.error)}</Alert>}
      <Field label="What was done" required>
        <Input value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
      </Field>
    </Dialog>
  );
}
