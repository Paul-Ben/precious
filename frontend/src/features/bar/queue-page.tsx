"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { BarOrder, BarOrderStatus } from "@/lib/api/types";
import { cn } from "@/lib/utils";
import { barApi, barKeys, minutesSince } from "./api";
import { pollEvery, useBarRealtime } from "./use-bar-realtime";

const COLUMNS: { status: BarOrderStatus; title: string; next?: BarOrderStatus; action?: string }[] = [
  { status: "PLACED", title: "New", next: "ACCEPTED", action: "Accept" },
  { status: "ACCEPTED", title: "Accepted", next: "PREPARING", action: "Start" },
  { status: "PREPARING", title: "Preparing", next: "READY", action: "Ready" },
  { status: "READY", title: "Ready for pickup" },
];

/** Bartender live queue (spec §25). Live over Reverb; polls as a fallback. */
export function QueuePage() {
  return (
    <RequirePermission permission={["bar.orders.prepare", "bar.orders.view"]}>
      <Queue />
    </RequirePermission>
  );
}

function Queue() {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const { connected } = useBarRealtime();
  const queue = useQuery({ queryKey: barKeys.queue, queryFn: barApi.queue, refetchInterval: pollEvery(connected, 4_000) });
  const [now, setNow] = useState(() => Date.now());
  const [showStock, setShowStock] = useState(false);

  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(t);
  }, []);

  const advance = useMutation({
    mutationFn: ({ id, status }: { id: string; status: BarOrderStatus }) => barApi.advance(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: barKeys.queue }),
  });

  const orders = queue.data ?? [];

  return (
    <>
      <PageHeader
        title="Bar queue"
        description={connected ? "Oldest first. Live." : "Oldest first. Refreshes every few seconds."}
        actions={can("bar.orders.prepare") && <Button variant="outline" onClick={() => setShowStock((s) => !s)}>{showStock ? "Hide" : "Sold out items"}</Button>}
      />
      {showStock && <StockPanel />}
      {advance.isError && <Alert tone="danger" className="mb-4">{errorMessage(advance.error)}</Alert>}
      {queue.isPending ? (
        <LoadingState />
      ) : queue.isError ? (
        <ErrorState error={queue.error} onRetry={() => queue.refetch()} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {COLUMNS.map((col) => {
            const list = orders.filter((o) => o.status === col.status);
            return (
              <section key={col.status} aria-label={col.title} className="space-y-3">
                <h2 className="flex items-center justify-between text-sm font-bold uppercase tracking-wider text-muted">
                  {col.title} <span className="rounded-full bg-surface-muted px-2 py-0.5 text-xs">{list.length}</span>
                </h2>
                {list.map((o) => (
                  <OrderCard key={o.id} order={o} now={now}
                    action={col.next && can("bar.orders.prepare") ? { label: col.action!, onClick: () => advance.mutate({ id: o.id, status: col.next! }), loading: advance.isPending && advance.variables?.id === o.id } : undefined} />
                ))}
              </section>
            );
          })}
        </div>
      )}
    </>
  );
}

function OrderCard({ order: o, now, action }: { order: BarOrder; now: number; action?: { label: string; onClick: () => void; loading: boolean } }) {
  const age = o.placed_at ? (now - Date.parse(o.placed_at)) / 60000 : 0;
  return (
    <Card className={cn(age > 15 && o.status !== "READY" && "border-danger")}>
      <CardBody className="space-y-2">
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-lg font-bold">{o.tab?.table ?? "No table"}</span>
          <span className={cn("text-xs", age > 15 ? "font-semibold text-danger" : "text-muted")}>{minutesSince(o.placed_at, now)}</span>
        </div>
        <p className="text-xs text-muted">{o.number} · {o.waiter?.name ?? ""}{o.tab?.customer_name ? ` · ${o.tab.customer_name}` : ""}</p>
        <ul className="space-y-1 text-base">
          {o.items?.map((i) => (
            <li key={i.id}>
              <strong>{i.quantity}×</strong> {i.name}
              {i.notes && <span className="block text-sm font-medium text-warning">↳ {i.notes}</span>}
            </li>
          ))}
        </ul>
        {o.notes && <p className="rounded-lg bg-warning-soft px-2 py-1 text-sm">{o.notes}</p>}
        {action && <Button size="lg" className="w-full" loading={action.loading} onClick={action.onClick}>{action.label}</Button>}
      </CardBody>
    </Card>
  );
}

function StockPanel() {
  const queryClient = useQueryClient();
  const menu = useQuery({ queryKey: barKeys.menu, queryFn: barApi.menu });
  const toggle = useMutation({
    mutationFn: ({ id, value }: { id: number; value: boolean }) => barApi.setAvailability(id, value),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: barKeys.menu }),
  });

  return (
    <Card className="mb-4">
      <CardHeader title="Sold out" description="Untick an item when it runs out; waiters can't order it until it's back." />
      <CardBody className="grid gap-1 sm:grid-cols-2 lg:grid-cols-4">
        {(menu.data ?? []).flatMap((c) => c.products).map((p) => (
          <Checkbox key={p.id} label={p.name} checked={p.is_available} onChange={(e) => toggle.mutate({ id: p.id, value: e.target.checked })} />
        ))}
      </CardBody>
    </Card>
  );
}
