"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { ArrowLeft, Mail, Printer } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { errorMessage } from "@/lib/api/errors";
import type { Receipt } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { paymentKeys, paymentsApi } from "./api";

export function ReceiptView({ number }: { number: string }) {
  return (
    <RequirePermission permission={["payments.view", "reservations.view"]}>
      <Inner number={number} />
    </RequirePermission>
  );
}

function Inner({ number }: { number: string }) {
  const q = useQuery({ queryKey: paymentKeys.receipt(number), queryFn: () => paymentsApi.receipt(number) });

  if (q.isPending) return <LoadingState />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;

  return <Printable receipt={q.data} />;
}

function Printable({ receipt: r }: { receipt: Receipt }) {
  const [email, setEmail] = useState(r.guest_email ?? "");
  const send = useMutation({ mutationFn: () => paymentsApi.emailReceipt(r.number, email.trim() || undefined) });

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <div className="flex flex-wrap items-center gap-3 print:hidden">
        <Link href={`/staff/payments/${r.payment_id}`} className="inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden="true" /> Payment
        </Link>
        <span className="flex-1" />
        <Button variant="outline" onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden="true" /> Print
        </Button>
        <Input aria-label="Email receipt to" type="email" className="w-56" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="guest@example.com" />
        <Button variant="outline" loading={send.isPending} disabled={!email} onClick={() => send.mutate()}>
          <Mail className="size-4" aria-hidden="true" /> Email
        </Button>
      </div>
      {send.isSuccess && <Alert tone="success" className="print:hidden">{send.data.message}</Alert>}
      {send.isError && <Alert tone="danger" className="print:hidden">{errorMessage(send.error)}</Alert>}

      <article className="rounded-2xl border border-border bg-surface p-8 text-sm print:border-0 print:p-0">
        <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
          <div>
            <p className="font-[family-name:var(--font-display)] text-2xl">{r.hotel.name}</p>
            {r.hotel.legal_name && <p className="text-xs text-muted">{r.hotel.legal_name}</p>}
            <p className="text-xs text-muted">{[r.hotel.address, r.hotel.phone, r.hotel.email].filter(Boolean).join(" · ")}</p>
          </div>
          <div className="text-right">
            <p className="text-xs uppercase tracking-widest text-muted">Receipt</p>
            <p className="font-mono text-lg font-bold">{r.number}</p>
            <p className="text-xs text-muted">{formatDateTime(r.issued_at)}</p>
          </div>
        </header>

        <section className="grid gap-4 border-b border-border py-5 sm:grid-cols-2">
          <div>
            <p className="text-xs uppercase tracking-wider text-muted">Received from</p>
            <p className="font-medium">{r.received_from ?? "—"}</p>
            {r.guest_email && <p className="text-xs text-muted">{r.guest_email}</p>}
          </div>
          <div>
            <p className="text-xs uppercase tracking-wider text-muted">For reservation</p>
            <p className="font-mono font-medium">{r.for.number}</p>
            <p className="text-xs text-muted">{formatStayDate(r.for.check_in, true)} → {formatStayDate(r.for.check_out, true)} · {r.for.nights} night{r.for.nights === 1 ? "" : "s"}</p>
          </div>
        </section>

        <dl className="space-y-2 py-5">
          <div className="flex justify-between"><dt className="text-muted">Payment method</dt><dd>{r.payment.method_label}{r.payment.channel ? ` (${r.payment.channel.replace("_", " ")})` : ""}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Reference</dt><dd className="font-mono text-xs">{r.payment.external_reference ?? r.payment.reference}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Amount received</dt><dd className="font-medium">{formatNaira(r.amount, { kobo: true })}</dd></div>
          {Number(r.processing_fee) > 0 && (
            <>
              <div className="flex justify-between"><dt className="text-muted">Payment processing fee</dt><dd>{formatNaira(r.processing_fee, { kobo: true })}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Total charged</dt><dd>{formatNaira(r.total_charged, { kobo: true })}</dd></div>
            </>
          )}
        </dl>

        <dl className="space-y-2 border-t border-border pt-5">
          <div className="flex justify-between"><dt className="text-muted">Reservation total</dt><dd>{formatNaira(r.reservation_total, { kobo: true })}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Paid to date</dt><dd>{formatNaira(r.total_paid_to_date, { kobo: true })}</dd></div>
          <div className="flex justify-between text-base font-bold"><dt>Balance</dt><dd>{formatNaira(r.balance_after, { kobo: true })}</dd></div>
        </dl>

        <footer className="mt-6 text-xs text-muted">
          {r.received_by ? `Received by ${r.received_by}. ` : ""}Thank you for staying with us.
        </footer>
      </article>
    </div>
  );
}
