"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Copy, ExternalLink, PlugZap, XCircle } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { api } from "@/lib/api/client";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { GatewayMode, PaymentGateway } from "@/lib/api/types";
import { cn, formatDateTime } from "@/lib/utils";

const gatewaysKey = ["payment-gateways"] as const;

export function PaymentGatewaysPage() {
  return (
    <RequirePermission permission="settings.payment_gateways.manage">
      <GatewayList />
    </RequirePermission>
  );
}

function GatewayList() {
  const gateways = useQuery({
    queryKey: gatewaysKey,
    queryFn: async () => (await api.get<PaymentGateway[]>("settings/payment-gateways")).data,
  });

  return (
    <>
      <PageHeader
        title="Payment gateways"
        description="Enter the API keys from your Paystack and Flutterwave dashboards. Secret keys are encrypted and never shown again after saving."
      />
      {gateways.isPending ? (
        <LoadingState />
      ) : gateways.isError ? (
        <ErrorState error={gateways.error} onRetry={() => gateways.refetch()} />
      ) : gateways.data.length === 0 ? (
        <EmptyState title="No gateways available" />
      ) : (
        <div className="space-y-6">
          {gateways.data.map((g) => (
            <GatewayCard key={g.gateway} gateway={g} />
          ))}
        </div>
      )}
    </>
  );
}

type CredentialDraft = Record<GatewayMode, Record<string, string>>;

function GatewayCard({ gateway }: { gateway: PaymentGateway }) {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<GatewayMode>(gateway.mode);
  const [tab, setTab] = useState<GatewayMode>(gateway.mode);
  const [enabled, setEnabled] = useState(gateway.is_enabled);
  const [isDefault, setIsDefault] = useState(gateway.is_default);
  const [draft, setDraft] = useState<CredentialDraft>({ test: {}, live: {} });
  const [feedback, setFeedback] = useState<{ tone: "success" | "danger" | "warning"; text: string } | null>(null);
  const [copied, setCopied] = useState(false);

  const replaceCache = (updated: PaymentGateway) => {
    queryClient.setQueryData<PaymentGateway[]>(gatewaysKey, (list) =>
      list?.map((g) => (g.gateway === updated.gateway ? updated : g)),
    );
    // Other gateways may have lost their default flag.
    void queryClient.invalidateQueries({ queryKey: gatewaysKey });
  };

  const save = useMutation({
    mutationFn: () =>
      api.patch<PaymentGateway>(`settings/payment-gateways/${gateway.gateway}`, {
        mode,
        is_enabled: enabled,
        is_default: enabled && isDefault,
        credentials: draft,
      }),
    onSuccess: (res) => {
      replaceCache(res.data);
      setDraft({ test: {}, live: {} });
      setFeedback({ tone: "success", text: res.message });
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        setFeedback({ tone: "danger", text: Object.values(error.errors).flat().join(" ") });
      } else {
        setFeedback({ tone: "danger", text: errorMessage(error) });
      }
    },
  });

  const test = useMutation({
    mutationFn: (testMode: GatewayMode) =>
      api.post<{ result: { success: boolean; message: string }; gateway: PaymentGateway }>(
        `settings/payment-gateways/${gateway.gateway}/test`,
        { mode: testMode },
      ),
    onSuccess: (res) => {
      replaceCache(res.data.gateway);
      setFeedback({ tone: res.data.result.success ? "success" : "warning", text: res.data.result.message });
    },
    onError: (error) => setFeedback({ tone: "danger", text: errorMessage(error) }),
  });

  const status = gateway.is_enabled ? (
    <Badge tone="success">Enabled · {gateway.mode === "live" ? "Live" : "Test"} mode</Badge>
  ) : (
    <Badge>Disabled</Badge>
  );

  return (
    <Card>
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            {gateway.name}
            {gateway.is_default && <Badge tone="brand">Default</Badge>}
          </span>
        }
        description={
          <a href={gateway.dashboard_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 underline underline-offset-4">
            Get your keys from the {gateway.name} dashboard <ExternalLink className="size-3" aria-hidden="true" />
          </a>
        }
        actions={status}
      />
      <CardBody className="space-y-6">
        {feedback && <Alert tone={feedback.tone}>{feedback.text}</Alert>}

        <form
          className="space-y-6"
          onSubmit={(e) => {
            e.preventDefault();
            setFeedback(null);
            save.mutate();
          }}
        >
          {/* Credentials per mode */}
          <div>
            <div role="tablist" aria-label={`${gateway.name} key sets`} className="mb-4 inline-flex rounded-lg bg-surface-muted p-1">
              {(["test", "live"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  role="tab"
                  aria-selected={tab === m}
                  onClick={() => setTab(m)}
                  className={cn(
                    "rounded-md px-4 py-1.5 text-sm font-medium",
                    tab === m ? "bg-surface shadow-sm" : "text-muted hover:text-foreground",
                  )}
                >
                  {m === "test" ? "Test keys" : "Live keys"}
                  {gateway.missing[m].length === 0 && <CheckCircle2 className="ml-1 inline size-3.5 text-success" aria-label="complete" />}
                </button>
              ))}
            </div>

            <div role="tabpanel" className="grid gap-4 md:grid-cols-2">
              {gateway.fields.map((field) => {
                const saved = gateway.credentials[tab]?.[field.key];
                return (
                  <Field
                    key={`${tab}-${field.key}`}
                    label={field.label}
                    required={field.required}
                    hint={
                      <>
                        {field.help}
                        {saved?.set && (
                          <span className="mt-0.5 block">
                            Saved: <code className="font-mono">{saved.preview}</code>
                            {field.secret && " - leave blank to keep it."}
                          </span>
                        )}
                      </>
                    }
                  >
                    <Input
                      type={field.secret ? "password" : "text"}
                      autoComplete="off"
                      spellCheck={false}
                      placeholder={saved?.set ? (field.secret ? "•••••••• (unchanged)" : (saved.preview ?? "")) : ""}
                      value={draft[tab][field.key] ?? ""}
                      onChange={(e) =>
                        setDraft((d) => ({ ...d, [tab]: { ...d[tab], [field.key]: e.target.value } }))
                      }
                    />
                  </Field>
                );
              })}
            </div>

            {gateway.missing[tab].length > 0 && (
              <p className="mt-3 text-xs text-muted">Still needed for {tab} mode: {gateway.missing[tab].join(", ")}.</p>
            )}
          </div>

          {/* Mode & status */}
          <div className="grid gap-4 rounded-lg border border-border p-4 md:grid-cols-3">
            <fieldset>
              <legend className="mb-2 text-sm font-medium">Active mode</legend>
              <div className="flex gap-4 text-sm">
                {(["test", "live"] as const).map((m) => (
                  <label key={m} className="flex items-center gap-2">
                    <input type="radio" name={`${gateway.gateway}-mode`} value={m} checked={mode === m} onChange={() => setMode(m)} className="accent-[var(--brand)]" />
                    {m === "test" ? "Test" : "Live"}
                  </label>
                ))}
              </div>
              {mode === "live" && <p className="mt-2 text-xs text-warning">Live mode charges real money.</p>}
            </fieldset>
            <Checkbox label="Enabled" description="Offer this gateway to customers." checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
            <Checkbox
              label="Default gateway"
              description="Used when a customer pays online."
              checked={enabled && isDefault}
              disabled={!enabled}
              onChange={(e) => setIsDefault(e.target.checked)}
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" loading={save.isPending}>
              Save settings
            </Button>
            <Button
              type="button"
              variant="outline"
              loading={test.isPending}
              disabled={!gateway.credentials[tab]?.secret_key?.set}
              onClick={() => {
                setFeedback(null);
                test.mutate(tab);
              }}
            >
              <PlugZap className="size-4" aria-hidden="true" /> Test connection ({tab === "test" ? "test keys" : "live keys"})
            </Button>
          </div>
        </form>

        <div className="space-y-2 border-t border-border pt-4 text-sm">
          <p className="font-medium">Webhook URL</p>
          <p className="text-muted">
            Add this URL in your {gateway.name} dashboard so payments are confirmed even if the customer closes the page.
            (Payment processing is switched on in the Payments milestone.)
          </p>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded-md bg-surface-muted px-3 py-2 font-mono text-xs">{gateway.webhook_url}</code>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={async () => {
                await navigator.clipboard?.writeText(gateway.webhook_url);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
              }}
            >
              <Copy className="size-4" aria-hidden="true" /> {copied ? "Copied" : "Copy"}
            </Button>
          </div>
          {gateway.last_test && (
            <p className="flex items-center gap-1.5 text-xs text-muted">
              {gateway.last_test.succeeded ? (
                <CheckCircle2 className="size-3.5 text-success" aria-hidden="true" />
              ) : (
                <XCircle className="size-3.5 text-danger" aria-hidden="true" />
              )}
              Last check with {gateway.last_test.mode} keys, {formatDateTime(gateway.last_test.tested_at)}: {gateway.last_test.message}
            </p>
          )}
        </div>
      </CardBody>
    </Card>
  );
}
