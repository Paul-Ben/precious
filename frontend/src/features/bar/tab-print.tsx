"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect } from "react";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { barApi, barKeys } from "./api";

/** Printable bill / charge-to-room slip (P9: the guest signs it). */
export function TabPrint({ id }: { id: string }) {
  return (
    <RequirePermission permission="bar.orders.view">
      <Slip id={id} />
    </RequirePermission>
  );
}

function Slip({ id }: { id: string }) {
  const q = useQuery({ queryKey: barKeys.tab(id), queryFn: () => barApi.tab(id) });

  useEffect(() => {
    if (q.data) {
      const t = setTimeout(() => window.print(), 300);
      return () => clearTimeout(t);
    }
  }, [q.data]);

  if (q.isPending) return <LoadingState />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const t = q.data;
  const room = t.settlement === "CHARGED_TO_ROOM";

  return (
    <article className="mx-auto max-w-sm bg-white p-4 font-mono text-sm text-black">
      <p className="text-center text-base font-bold">{room ? "ROOM CHARGE SLIP" : "BAR BILL"}</p>
      <p className="text-center">{t.number} · {t.table?.name ?? "No table"}</p>
      <p className="text-center text-xs">{formatDateTime(t.opened_at)}{t.waiter ? ` · ${t.waiter.name}` : ""}</p>
      <hr className="my-2 border-dashed border-black" />
      {(t.orders ?? []).filter((o) => o.status !== "CANCELLED").flatMap((o) => o.items ?? []).map((i) => (
        <div key={i.id} className="flex justify-between gap-2"><span>{i.quantity} {i.name}</span><span>{formatNaira(i.line_total)}</span></div>
      ))}
      <hr className="my-2 border-dashed border-black" />
      <div className="flex justify-between"><span>Items</span><span>{formatNaira(t.subtotal, { kobo: true })}</span></div>
      {Number(t.discount) > 0 && <div className="flex justify-between"><span>Discount</span><span>-{formatNaira(t.discount, { kobo: true })}</span></div>}
      <div className="flex justify-between"><span>Service charge</span><span>{formatNaira(t.service_charge, { kobo: true })}</span></div>
      <div className="flex justify-between"><span>VAT</span><span>{formatNaira(t.vat, { kobo: true })}</span></div>
      <div className="flex justify-between font-bold"><span>TOTAL</span><span>{formatNaira(t.total, { kobo: true })}</span></div>
      {!room && <div className="flex justify-between"><span>Paid</span><span>{formatNaira(t.amount_paid, { kobo: true })}</span></div>}
      {!room && <div className="flex justify-between font-bold"><span>To pay</span><span>{formatNaira(t.balance, { kobo: true })}</span></div>}
      {room && (
        <div className="mt-6 space-y-6">
          <p>Charged to room <strong>{t.charged_to?.room}</strong>.</p>
          <p>Guest signature: ______________________</p>
          <p>Name: ______________________</p>
        </div>
      )}
      <p className="mt-4 text-center text-xs">Thank you!</p>
    </article>
  );
}
