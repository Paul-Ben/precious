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
import type { FolioStatement } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { stayApi, stayKeys } from "./api";

export function FolioView({ number }: { number: string }) {
  return (
    <RequirePermission permission={["reservations.view", "payments.view"]}>
      <Inner number={number} />
    </RequirePermission>
  );
}

function Inner({ number }: { number: string }) {
  const q = useQuery({ queryKey: stayKeys.folio(number), queryFn: () => stayApi.folio(number) });
  if (q.isPending) return <LoadingState />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  return <Printable f={q.data} />;
}

function Printable({ f }: { f: FolioStatement }) {
  const [email, setEmail] = useState(f.guest.email ?? "");
  const send = useMutation({ mutationFn: () => stayApi.emailFolio(f.number, email.trim() || undefined) });
  const balance = Number(f.balance);

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center gap-3 print:hidden">
        <Link href={`/staff/reservations/${f.reservation_id}`} className="inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden="true" /> Reservation
        </Link>
        <span className="flex-1" />
        <Button variant="outline" onClick={() => window.print()}><Printer className="size-4" aria-hidden="true" /> Print</Button>
        <Input aria-label="Email to" type="email" className="w-56" value={email} onChange={(e) => setEmail(e.target.value)} />
        <Button variant="outline" loading={send.isPending} disabled={!email} onClick={() => send.mutate()}><Mail className="size-4" aria-hidden="true" /> Email</Button>
      </div>
      {send.isSuccess && <Alert tone="success" className="print:hidden">{send.data.message}</Alert>}
      {send.isError && <Alert tone="danger" className="print:hidden">{errorMessage(send.error)}</Alert>}

      <article className="rounded-2xl border border-border bg-surface p-8 text-sm print:border-0 print:p-0">
        <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
          <div>
            <p className="font-[family-name:var(--font-display)] text-2xl">{f.hotel.name}</p>
            {f.hotel.legal_name && <p className="text-xs text-muted">{f.hotel.legal_name}</p>}
            <p className="text-xs text-muted">{[f.hotel.address, f.hotel.phone, f.hotel.email].filter(Boolean).join(" · ")}</p>
          </div>
          <div className="text-right">
            <p className="text-xs uppercase tracking-widest text-muted">Final bill</p>
            <p className="font-mono text-lg font-bold">{f.number}</p>
            <p className="text-xs text-muted">{formatDateTime(f.issued_at)}</p>
          </div>
        </header>

        <section className="grid gap-4 border-b border-border py-5 sm:grid-cols-2">
          <div>
            <p className="text-xs uppercase tracking-wider text-muted">Guest</p>
            <p className="font-medium">{f.guest.name}</p>
            <p className="text-xs text-muted">{[f.guest.email, f.guest.phone].filter(Boolean).join(" · ")}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wider text-muted">Reservation</p>
            <p className="font-mono font-medium">{f.reservation.number}</p>
            <p className="text-xs text-muted">
              {formatStayDate(f.reservation.check_in, true)} → {formatStayDate(f.reservation.check_out, true)} · rooms {f.accommodation.rooms.map((r) => r.room_number).join(", ")}
            </p>
          </div>
        </section>

        <table className="my-5 w-full text-left">
          <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
            <tr><th className="py-2 font-medium">Item</th><th className="py-2 text-right font-medium">Amount</th></tr>
          </thead>
          <tbody className="divide-y divide-border">
            <tr>
              <td className="py-2">Accommodation · {f.accommodation.nights} night{f.accommodation.nights === 1 ? "" : "s"} (incl. VAT {formatNaira(f.accommodation.vat, { kobo: true })})</td>
              <td className="py-2 text-right">{formatNaira(f.accommodation.total, { kobo: true })}</td>
            </tr>
            {f.charges.map((c) => (
              <tr key={c.id}>
                <td className="py-2">
                  {c.description}{Number(c.quantity) !== 1 ? ` × ${Number(c.quantity)}` : ""}
                  {(Number(c.service_charge) !== 0 || Number(c.vat) !== 0) && (
                    <span className="block text-xs text-muted">
                      {formatNaira(c.subtotal, { kobo: true })}{Number(c.service_charge) ? ` + SC ${formatNaira(c.service_charge, { kobo: true })}` : ""}{Number(c.vat) ? ` + VAT ${formatNaira(c.vat, { kobo: true })}` : ""}
                    </span>
                  )}
                </td>
                <td className="py-2 text-right">{formatNaira(c.total, { kobo: true })}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <dl className="space-y-2 border-t border-border pt-4">
          <div className="flex justify-between font-bold"><dt>Total</dt><dd>{formatNaira(f.grand_total, { kobo: true })}</dd></div>
          {f.payments.map((p, i) => (
            <div key={i} className="flex justify-between text-muted">
              <dt>{p.method}{p.receipt_number ? ` · ${p.receipt_number}` : ""} · {formatDateTime(p.date)}</dt>
              <dd>−{formatNaira(p.amount, { kobo: true })}{Number(p.refunded) > 0 ? ` (refunded ${formatNaira(p.refunded, { kobo: true })})` : ""}</dd>
            </div>
          ))}
          <div className="flex justify-between text-base font-bold">
            <dt>{balance > 0 ? "Balance outstanding" : balance < 0 ? "Credit due to guest" : "Balance"}</dt>
            <dd>{formatNaira(Math.abs(balance).toFixed(2), { kobo: true })}</dd>
          </div>
        </dl>
        <footer className="mt-6 text-xs text-muted">Checked out {formatDateTime(f.reservation.checked_out_at)}. Thank you for staying with us.</footer>
      </article>
    </div>
  );
}
