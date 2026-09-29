"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Clock, XCircle } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Alert } from "@/components/ui/alert";
import { Card, CardBody } from "@/components/ui/card";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { bookingTokenStore } from "@/features/booking/api";
import { formatNaira } from "@/lib/money";
import { paymentsApi, referenceFromQuery } from "./api";

const MAX_CHECKS = 6;

/** Landing page after Paystack / Flutterwave: asks our API to verify the payment. */
export function PaymentCallback() {
  const params = useSearchParams();
  const reference = referenceFromQuery(new URLSearchParams(params.toString()));

  const queryClient = useQueryClient();
  const queryKey = ["payments", "verify", reference] as const;
  const check = useQuery({
    queryKey,
    queryFn: () => paymentsApi.verify(reference ?? ""),
    enabled: !!reference,
    retry: 1,
    // Gateways can take a few seconds to settle - keep asking while pending.
    refetchInterval: (q) =>
      q.state.data?.status === "PENDING" && q.state.dataUpdateCount < MAX_CHECKS ? 3000 : false,
  });

  if (!reference) return <Alert tone="danger">This payment link is incomplete. Please check your reservation for its payment status.</Alert>;
  if (check.isPending) return <LoadingState label="Confirming your payment…" />;
  if (check.isError) return <ErrorState error={check.error} onRetry={() => check.refetch()} />;

  const v = check.data;
  const tabNumber = v.bar_tab?.number;
  const number = v.reservation?.number ?? tabNumber;
  const token = number ? bookingTokenStore.read(number) : null;
  const backHref = tabNumber
    ? `/bar/pay/${encodeURIComponent(tabNumber)}${token ? `?token=${token}` : ""}`
    : number && token ? `/book/confirmation/${encodeURIComponent(number)}` : "/account";
  const checks = queryClient.getQueryState(queryKey)?.dataUpdateCount ?? 1;
  const stillChecking = v.status === "PENDING" && checks < MAX_CHECKS;

  return (
    <Card>
      <CardBody className="space-y-5 py-10 text-center">
        {v.status === "SUCCESSFUL" ? (
          <>
            <CheckCircle2 className="mx-auto size-12 text-success" aria-hidden="true" />
            <h1 className="font-[family-name:var(--font-display)] text-3xl">Payment received</h1>
            <p className="text-sm text-muted">
              {formatNaira(v.charged_amount, { kobo: true })} paid{v.receipt_number ? ` · receipt ${v.receipt_number}` : ""}.
              {v.reservation?.status === "CONFIRMED" && " Your reservation is confirmed."}
              {" "}A receipt has been emailed to you.
            </p>
            {v.reservation?.status === "EXPIRED" && (
              <Alert tone="warning">
                Your payment arrived after the hold on the rooms had ended and they were no longer available. The hotel has been notified and will
                contact you about a refund or another room.
              </Alert>
            )}
            {v.reservation && Number(v.reservation.balance) > 0 && v.reservation.status !== "EXPIRED" && (
              <p className="text-sm">Balance remaining: <strong>{formatNaira(v.reservation.balance, { kobo: true })}</strong></p>
            )}
          </>
        ) : v.status === "PENDING" ? (
          <>
            <Clock className="mx-auto size-12 text-warning" aria-hidden="true" />
            <h1 className="font-[family-name:var(--font-display)] text-3xl">{stillChecking ? "Confirming payment…" : "Payment not confirmed yet"}</h1>
            <p className="text-sm text-muted">
              {stillChecking
                ? "This usually takes a few seconds."
                : "If you completed the payment, it will be confirmed automatically within a few minutes and you'll get a receipt by email. You can safely close this page."}
            </p>
          </>
        ) : (
          <>
            <XCircle className="mx-auto size-12 text-danger" aria-hidden="true" />
            <h1 className="font-[family-name:var(--font-display)] text-3xl">Payment not completed</h1>
            <p className="text-sm text-muted">{v.failure_reason ?? "The payment was cancelled or declined."} No money was taken for this attempt.</p>
          </>
        )}

        {number && (
          <Link href={backHref} className="inline-block text-sm font-semibold underline underline-offset-4">
            {tabNumber ? (v.status === "SUCCESSFUL" ? "View your bill" : "Back to your bill") : v.status === "SUCCESSFUL" ? "View your reservation" : "Back to your reservation"} ({number})
          </Link>
        )}
      </CardBody>
    </Card>
  );
}
