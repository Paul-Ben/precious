"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Plus, Receipt as ReceiptIcon, Undo2 } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { hotelKeys } from "@/features/hotel/api";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Payment, Reservation } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { ATTENTION_LABELS, GATEWAY_NAMES, paymentsApi } from "./api";
import { TransactionStatusBadge } from "./badges";

/** Payments card on the staff reservation page. */
export function ReservationPayments({ r }: { r: Reservation }) {
  const { can } = useSession();
  const [recording, setRecording] = useState(false);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const payments = r.payments ?? [];
  const takesMoney = !["CANCELLED", "NO_SHOW", "DRAFT"].includes(r.status) && Number(r.balance) > 0;

  return (
    <Card>
      <CardHeader
        title="Payments"
        actions={
          can("payments.create") && takesMoney && (
            <Button size="sm" onClick={() => setRecording(true)}>
              <Plus className="size-4" aria-hidden="true" /> Record payment
            </Button>
          )
        }
      />
      {payments.length === 0 ? (
        <CardBody className="text-sm text-muted">No payments yet.</CardBody>
      ) : (
        <ul className="divide-y divide-border text-sm">
          {payments.map((p) => (
            <li key={p.id} className="space-y-1 px-5 py-3">
              <div className="flex items-center justify-between gap-2">
                <span className="font-semibold">{formatNaira(p.amount, { kobo: true })}</span>
                <TransactionStatusBadge status={p.status} />
              </div>
              <p className="text-xs text-muted">
                {p.method === "GATEWAY" ? GATEWAY_NAMES[p.gateway ?? ""] ?? "Online" : p.method_label}
                {p.channel ? ` (${p.channel.replace("_", " ")})` : ""} · {formatDateTime(p.paid_at ?? p.created_at)}
                {p.recorded_by ? ` · ${p.recorded_by.name}` : ""}
              </p>
              {Number(p.customer_fee) > 0 && <p className="text-xs text-muted">+ {formatNaira(p.customer_fee, { kobo: true })} processing fee paid by guest</p>}
              {Number(p.refunded_amount) > 0 && <p className="text-xs text-danger">Refunded {formatNaira(p.refunded_amount, { kobo: true })}</p>}
              {p.needs_attention && (
                <p className="flex items-start gap-1 text-xs font-medium text-warning">
                  <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                  {ATTENTION_LABELS[p.attention_reason ?? ""] ?? p.attention_reason}
                </p>
              )}
              <div className="flex flex-wrap gap-3 pt-1 text-xs">
                {p.receipt_number && (
                  <Link href={`/staff/receipts/${p.receipt_number}`} className="inline-flex items-center gap-1 font-medium hover:underline">
                    <ReceiptIcon className="size-3.5" aria-hidden="true" /> {p.receipt_number}
                  </Link>
                )}
                {can("payments.view") && (
                  <Link href={`/staff/payments/${p.id}`} className="font-medium text-muted hover:underline">
                    Details
                  </Link>
                )}
                {can("payments.refund") && p.status === "SUCCESSFUL" && Number(p.refundable ?? 0) > 0 && (
                  <button type="button" className="inline-flex items-center gap-1 font-medium text-danger hover:underline" onClick={() => setRefunding(p)}>
                    <Undo2 className="size-3.5" aria-hidden="true" /> Refund
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {recording && <RecordPaymentDialog r={r} onClose={() => setRecording(false)} />}
      {refunding && <RequestRefundDialog payment={refunding} reservationId={r.id} onClose={() => setRefunding(null)} />}
    </Card>
  );
}

const METHODS = [
  { value: "CASH", label: "Cash" },
  { value: "POS", label: "POS terminal" },
  { value: "BANK_TRANSFER", label: "Bank transfer" },
];

export function RecordPaymentDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const queryClient = useQueryClient();
  const depositDue = Math.max(0, Number(r.deposit_amount) - Number(r.amount_paid));
  const [form, setForm] = useState({
    method: "CASH",
    amount: r.balance,
    external_reference: "",
    note: "",
    send_receipt: !!r.guest?.email,
  });

  const record = useMutation({
    mutationFn: () =>
      paymentsApi.record(r.id, {
        method: form.method,
        amount: form.amount.trim(),
        external_reference: form.external_reference.trim() || undefined,
        note: form.note.trim() || undefined,
        send_receipt: form.send_receipt,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: hotelKeys.reservation(r.id) });
      await queryClient.invalidateQueries({ queryKey: ["payments"] });
      await queryClient.invalidateQueries({ queryKey: ["hotel", "summary"] });
      onClose();
    },
  });
  const err = isApiError(record.error) ? record.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Record payment · ${r.number}`}
      description={`Balance ${formatNaira(r.balance, { kobo: true })}${r.status === "PENDING_PAYMENT" && depositDue > 0 ? ` · ${formatNaira(depositDue.toFixed(2))} confirms the booking` : ""}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={record.isPending} disabled={!form.amount || Number(form.amount) <= 0} onClick={() => record.mutate()}>
            Record {form.amount ? formatNaira(form.amount, { kobo: true }) : ""}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {record.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-2">{errorMessage(record.error)}</Alert>}
        <Field label="Method" required error={err?.field("method")}>
          <Select value={form.method} onChange={(e) => setForm((f) => ({ ...f, method: e.target.value }))}>
            {METHODS.map((m) => (<option key={m.value} value={m.value}>{m.label}</option>))}
          </Select>
        </Field>
        <Field label="Amount (₦)" required error={err?.field("amount")}>
          <Input inputMode="decimal" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value.replace(/[^\d.]/g, "") }))} />
        </Field>
        <Field
          label={form.method === "BANK_TRANSFER" ? "Transfer reference / sender" : "POS slip or reference"}
          required={form.method === "BANK_TRANSFER"}
          error={err?.field("external_reference")}
          className="sm:col-span-2"
        >
          <Input value={form.external_reference} maxLength={100} onChange={(e) => setForm((f) => ({ ...f, external_reference: e.target.value }))} />
        </Field>
        <Field label="Note" className="sm:col-span-2">
          <Input value={form.note} maxLength={500} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} />
        </Field>
        <Checkbox
          className="sm:col-span-2"
          label="Email the receipt to the guest"
          description={r.guest?.email ?? "No email on file"}
          disabled={!r.guest?.email}
          checked={form.send_receipt}
          onChange={(e) => setForm((f) => ({ ...f, send_receipt: e.target.checked }))}
        />
      </div>
    </Dialog>
  );
}

export function RequestRefundDialog({ payment, reservationId, onClose }: { payment: Payment; reservationId?: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [amount, setAmount] = useState(payment.refundable ?? payment.amount);
  const [reason, setReason] = useState("");

  const request = useMutation({
    mutationFn: () => paymentsApi.requestRefund(payment.id, amount.trim(), reason.trim()),
    onSuccess: async () => {
      if (reservationId) await queryClient.invalidateQueries({ queryKey: hotelKeys.reservation(reservationId) });
      await queryClient.invalidateQueries({ queryKey: ["payments"] });
      onClose();
    },
  });
  const err = isApiError(request.error) ? request.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title="Request a refund"
      description={`Up to ${formatNaira(payment.refundable ?? payment.amount, { kobo: true })} of this ${payment.method_label.toLowerCase()} payment. Processing fees are not refundable.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button variant="danger" loading={request.isPending} disabled={reason.trim().length < 5 || !amount} onClick={() => request.mutate()}>
            Request refund
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {request.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(request.error)}</Alert>}
        <Field label="Amount (₦)" required error={err?.field("amount")}>
          <Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ""))} />
        </Field>
        <Field label="Reason" required error={err?.field("reason")} hint="At least 5 characters. Shown to the approver and in the audit log.">
          <Input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <p className="text-xs text-muted">Refunds above the approval limit (₦100,000 by default) must be approved by another person with refund permission.</p>
      </div>
    </Dialog>
  );
}
