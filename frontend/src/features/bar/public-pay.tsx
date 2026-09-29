"use client";

import { useQuery } from "@tanstack/react-query";
import { useSearchParams } from "next/navigation";
import { useEffect } from "react";
import { Alert } from "@/components/ui/alert";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { bookingTokenStore } from "@/features/booking/api";
import { PayPanel } from "@/features/payments/pay-panel";
import { formatNaira } from "@/lib/money";
import { barApi } from "./api";

/** The customer's bar bill from the emailed link (spec §26). */
export function PublicBarPay({ number }: { number: string }) {
  const params = useSearchParams();
  const token = params.get("token") ?? bookingTokenStore.read(number) ?? "";

  useEffect(() => {
    // Remembered so the payment result page can link back here.
    if (token) bookingTokenStore.save(number, token);
  }, [number, token]);

  const tab = useQuery({ queryKey: ["public", "bar-tab", number], queryFn: () => barApi.publicTab(number, token), enabled: token.length === 40 });

  if (token.length !== 40) return <Alert tone="danger">This link is incomplete. Please use the link from your bill email.</Alert>;
  if (tab.isPending) return <LoadingState label="Loading your bill…" />;
  if (tab.isError) return <ErrorState error={tab.error} onRetry={() => tab.refetch()} />;
  const t = tab.data;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title={`Bill ${t.number}`} description={t.table?.name ?? undefined} />
        <CardBody className="space-y-3 text-sm">
          <ul className="space-y-1">
            {(t.orders ?? []).filter((o) => o.status !== "CANCELLED").flatMap((o) => o.items ?? []).map((i) => (
              <li key={i.id} className="flex justify-between"><span>{i.quantity} × {i.name}</span><span>{formatNaira(i.line_total)}</span></li>
            ))}
          </ul>
          <dl className="space-y-1 border-t border-border pt-3">
            <div className="flex justify-between"><dt className="text-muted">Items</dt><dd>{formatNaira(t.subtotal, { kobo: true })}</dd></div>
            {Number(t.discount) > 0 && <div className="flex justify-between"><dt className="text-muted">Discount</dt><dd>−{formatNaira(t.discount, { kobo: true })}</dd></div>}
            <div className="flex justify-between"><dt className="text-muted">Service charge</dt><dd>{formatNaira(t.service_charge, { kobo: true })}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">VAT</dt><dd>{formatNaira(t.vat, { kobo: true })}</dd></div>
            <div className="flex justify-between font-bold"><dt>Total</dt><dd>{formatNaira(t.total, { kobo: true })}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Paid</dt><dd>{formatNaira(t.amount_paid, { kobo: true })}</dd></div>
            <div className="flex justify-between text-base font-bold"><dt>Outstanding</dt><dd>{formatNaira(t.balance, { kobo: true })}</dd></div>
          </dl>
        </CardBody>
      </Card>
      {t.status === "OPEN" && Number(t.balance) > 0 ? (
        <PayPanel payer={{ kind: "tab", number: t.number, token }} title="Pay your bill" />
      ) : (
        <Alert tone="success">{t.settlement === "CHARGED_TO_ROOM" ? "This bill was charged to your room." : "This bill is fully paid. Thank you!"}</Alert>
      )}
    </div>
  );
}
