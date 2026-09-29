"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { Lock } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { errorMessage } from "@/lib/api/errors";
import type { PaymentOptions } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { type Payer, paymentKeys, paymentsApi } from "./api";

/**
 * Lets a guest or customer choose deposit / full amount and a gateway, shows
 * the processing fee, and sends them to the gateway's secure page.
 */
export function PayPanel({ payer, title = "Pay now" }: { payer: Payer; title?: string }) {
  const options = useQuery({ queryKey: paymentKeys.options(payer), queryFn: () => paymentsApi.options(payer) });

  if (options.isPending) return <LoadingState label="Loading payment options…" />;
  if (options.isError) return <ErrorState error={options.error} onRetry={() => options.refetch()} />;
  if (!options.data.payable) return options.data.reason ? <Alert tone="info">{options.data.reason}</Alert> : null;

  return <Chooser payer={payer} data={options.data} title={title} />;
}

function Chooser({ payer, data, title }: { payer: Payer; data: PaymentOptions; title: string }) {
  const [option, setOption] = useState(data.options[0]?.option ?? "balance");
  const [gateway, setGateway] = useState(data.gateways[0]?.gateway ?? null);

  const start = useMutation({
    mutationFn: () => paymentsApi.start(payer, option, gateway),
    // Leave the page for the gateway's hosted checkout.
    onSuccess: (checkout) => window.location.assign(checkout.authorization_url),
  });

  if (data.gateways.length === 0) {
    return <Alert tone="info">Online payment is not available right now. Please contact the hotel to pay.</Alert>;
  }

  const chosen = data.options.find((o) => o.option === option) ?? data.options[0];
  const line = chosen.by_gateway.find((g) => g.gateway === gateway) ?? chosen.by_gateway[0];
  const testMode = data.gateways.find((g) => g.gateway === gateway)?.test_mode;

  return (
    <Card>
      <CardHeader title={title} actions={testMode ? <Badge tone="warning">Test mode</Badge> : undefined} />
      <CardBody className="space-y-5">
        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium">Amount</legend>
          {data.options.map((o) => (
            <label
              key={o.option}
              className={cn(
                "flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm",
                option === o.option ? "border-brand bg-brand/5" : "border-border hover:bg-surface-muted",
              )}
            >
              <span className="flex items-center gap-3">
                <input type="radio" name="pay-option" value={o.option} checked={option === o.option} onChange={() => setOption(o.option)} className="accent-[var(--brand)]" />
                <span className="font-medium">{o.label}</span>
              </span>
              <span className="font-semibold">{formatNaira(o.amount)}</span>
            </label>
          ))}
        </fieldset>

        {data.gateways.length > 1 && (
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium">Pay with</legend>
            <div className="grid gap-2 sm:grid-cols-2">
              {data.gateways.map((g) => (
                <label
                  key={g.gateway}
                  className={cn(
                    "flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-sm",
                    gateway === g.gateway ? "border-brand bg-brand/5" : "border-border hover:bg-surface-muted",
                  )}
                >
                  <input type="radio" name="pay-gateway" value={g.gateway} checked={gateway === g.gateway} onChange={() => setGateway(g.gateway)} className="accent-[var(--brand)]" />
                  <span className="font-medium">{g.name}</span>
                </label>
              ))}
            </div>
          </fieldset>
        )}

        <dl className="space-y-1 rounded-xl bg-surface-muted px-4 py-3 text-sm">
          <div className="flex justify-between"><dt className="text-muted">{chosen.label}</dt><dd>{formatNaira(chosen.amount, { kobo: true })}</dd></div>
          {data.fees_passed_to_customer && (
            <div className="flex justify-between"><dt className="text-muted">Payment processing fee</dt><dd>{formatNaira(line.fee, { kobo: true })}</dd></div>
          )}
          <div className="flex justify-between border-t border-border pt-2 text-base font-bold"><dt>You pay</dt><dd>{formatNaira(line.total, { kobo: true })}</dd></div>
        </dl>

        {start.isError && <Alert tone="danger">{errorMessage(start.error)}</Alert>}

        <Button size="lg" className="w-full" loading={start.isPending || start.isSuccess} onClick={() => start.mutate()}>
          <Lock className="size-4" aria-hidden="true" /> Pay {formatNaira(line.total, { kobo: true })} securely
        </Button>
        <p className="text-center text-xs text-muted">
          You will be taken to {data.gateways.find((g) => g.gateway === gateway)?.name ?? "the payment provider"} to pay by card, bank transfer or USSD.
          {data.fees_passed_to_customer && " The processing fee is charged by the payment provider and is not refundable."}
        </p>
      </CardBody>
    </Card>
  );
}
