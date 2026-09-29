"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Clock } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { PayPanel } from "@/features/payments/pay-panel";
import type { Reservation } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { bookingApi, bookingKeys, bookingTokenStore } from "./api";
import { ReservationStatusBadge } from "./status-badge";

function useCountdown(until: string | null) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!until) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [until]);
  if (!until) return null;
  const ms = Date.parse(until) - now;
  if (ms <= 0) return "expired";
  const m = Math.floor(ms / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  return `${m}:${String(s).padStart(2, "0")}`;
}

export function BookingConfirmation({ number }: { number: string }) {
  const [token] = useState(() => (typeof window === "undefined" ? null : bookingTokenStore.read(number)));

  const reservation = useQuery({
    queryKey: bookingKeys.lookup(number),
    queryFn: () => bookingApi.lookup(number, token ?? ""),
    enabled: !!token,
    refetchInterval: 30_000,
  });

  if (!token) {
    return (
      <Alert tone="info" title={`Reservation ${number}`}>
        Open this page from the browser you booked on, or sign in to your account to see your reservation.
      </Alert>
    );
  }

  if (reservation.isPending) return <LoadingState label="Loading your reservation…" />;
  if (reservation.isError) return <ErrorState error={reservation.error} onRetry={() => reservation.refetch()} />;

  return <Details r={reservation.data} token={token} />;
}

function Details({ r, token }: { r: Reservation; token: string }) {
  const countdown = useCountdown(r.status === "PENDING_PAYMENT" ? r.expires_at : null);
  const heldOrPaid = r.status === "PENDING_PAYMENT" ? "Your rooms are held" : r.status === "CONFIRMED" ? "Your reservation is confirmed" : `Reservation ${r.number}`;
  const canPay = ["PENDING_PAYMENT", "CONFIRMED", "CHECKED_IN"].includes(r.status) && Number(r.balance) > 0 && countdown !== "expired";

  return (
    <div className="space-y-6">
      <div className="flex flex-col items-center gap-2 text-center">
        <CheckCircle2 className="size-10 text-success" aria-hidden="true" />
        <h1 className="font-[family-name:var(--font-display)] text-3xl">{heldOrPaid}</h1>
        <p className="text-sm text-muted">
          Reservation <strong className="font-mono text-foreground">{r.number}</strong> · <ReservationStatusBadge status={r.status} />
        </p>
      </div>

      {r.status === "PENDING_PAYMENT" && (
        <Alert tone="warning" title={countdown === "expired" ? "Hold expired" : `Complete payment within ${countdown}`}>
          {countdown === "expired"
            ? "The hold on these rooms has ended. Please search again to book."
            : `Pay the ${formatNaira(r.deposit_amount)} deposit (or the full amount) to confirm your reservation.`}
        </Alert>
      )}
      {canPay && (
        <PayPanel
          payer={{ kind: "guest", number: r.number, token }}
          title={r.status === "PENDING_PAYMENT" ? "Pay to confirm" : "Pay the balance"}
        />
      )}
      {r.status === "EXPIRED" && <Alert tone="danger">This hold expired before payment was received. The rooms have been released.</Alert>}

      <Card>
        <CardHeader title={`${formatStayDate(r.check_in, true)} → ${formatStayDate(r.check_out, true)}`} description={`${r.nights} ${r.nights === 1 ? "night" : "nights"} · ${r.adults} adults${r.children ? `, ${r.children} children` : ""}`} />
        <CardBody className="space-y-4 text-sm">
          <ul className="space-y-1">
            {r.rooms?.map((room) => (
              <li key={room.id} className="flex justify-between">
                <span>{room.room_type.name}</span>
                <span>{formatNaira(room.subtotal)}</span>
              </li>
            ))}
          </ul>
          <dl className="space-y-1 border-t border-border pt-3">
            <div className="flex justify-between"><dt className="text-muted">VAT</dt><dd>{formatNaira(r.tax_total)}</dd></div>
            <div className="flex justify-between font-bold"><dt>Total</dt><dd>{formatNaira(r.total)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Deposit to confirm</dt><dd>{formatNaira(r.deposit_amount)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Paid</dt><dd>{formatNaira(r.amount_paid, { kobo: true })}</dd></div>
            <div className="flex justify-between font-semibold"><dt>Balance</dt><dd>{formatNaira(r.balance, { kobo: true })}</dd></div>
          </dl>
          {r.payments && r.payments.length > 0 && (
            <div className="border-t border-border pt-3">
              <p className="mb-1 font-medium">Payments</p>
              <ul className="space-y-1">
                {r.payments.map((p) => (
                  <li key={p.id} className="flex justify-between gap-3">
                    <span className="text-muted">
                      {p.paid_at ? formatDateTime(p.paid_at) : ""} · {p.method_label}
                      {p.receipt_number ? ` · ${p.receipt_number}` : ""}
                    </span>
                    <span>{formatNaira(p.amount, { kobo: true })}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <p className="flex items-center gap-2 text-xs text-muted">
            <Clock className="size-3.5" aria-hidden="true" /> Booked {formatDateTime(r.created_at)} for {r.guest?.full_name}
          </p>
        </CardBody>
      </Card>

      <p className="text-center text-sm text-muted">
        <Link href="/account" className="font-medium text-foreground underline underline-offset-4">
          Sign in or create an account
        </Link>{" "}
        with {r.guest?.email ?? "your email"} to manage your bookings.
      </p>
    </div>
  );
}
