"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Minus, Plus, Search, Send, Trash2, UserRound } from "lucide-react";
import { useMemo, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { BarProduct, BarTable } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { barApi, barKeys } from "./api";
import { TabBill } from "./tab-bill";

const TABLE_TONE: Record<string, string> = {
  AVAILABLE: "border-success/40 bg-success-soft",
  OCCUPIED: "border-accent/60 bg-accent/15",
  RESERVED: "border-info/40 bg-info-soft",
  CLEANING: "border-warning/50 bg-warning-soft",
  BLOCKED: "border-border bg-surface-muted opacity-60",
};

export function PosPage() {
  return (
    <RequirePermission permission="bar.orders.create">
      <Pos />
    </RequirePermission>
  );
}

function Pos() {
  const [tabId, setTabId] = useState<string | null>(null);
  const [opening, setOpening] = useState<BarTable | "walk-up" | null>(null);
  const tables = useQuery({ queryKey: barKeys.tables, queryFn: () => barApi.tables(), refetchInterval: 10_000 });

  if (tabId) {
    return <TabWorkspace tabId={tabId} onBack={() => setTabId(null)} />;
  }

  return (
    <>
      <PageHeader
        title="Bar"
        description="Tap a table to open a bill or continue one."
        actions={<Button variant="outline" onClick={() => setOpening("walk-up")}>Bill without a table</Button>}
      />
      {tables.isPending ? (
        <LoadingState />
      ) : tables.isError ? (
        <ErrorState error={tables.error} onRetry={() => tables.refetch()} />
      ) : tables.data.length === 0 ? (
        <EmptyState title="No tables set up yet">Ask a manager to add tables in Bar setup.</EmptyState>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          {tables.data.map((t) => (
            <div key={t.id} className={cn("flex min-h-32 flex-col rounded-2xl border-2 p-3", TABLE_TONE[t.status])}>
              <button
                type="button"
                className="flex-1 text-left"
                disabled={t.status === "BLOCKED"}
                onClick={() => (t.open_tabs?.length === 1 ? setTabId(t.open_tabs[0].id) : setOpening(t))}
              >
                <span className="block text-lg font-bold">{t.name}</span>
                <span className="block text-xs text-muted">{t.area ?? ""} · {t.capacity} seats</span>
              </button>
              {(t.open_tabs ?? []).map((tab) => (
                <button key={tab.id} type="button" onClick={() => setTabId(tab.id)} className="mt-1 rounded-lg bg-surface/80 px-2 py-1.5 text-left text-xs font-medium hover:bg-surface">
                  {tab.customer_name || tab.number} · {formatNaira(tab.total)}
                </button>
              ))}
              {(t.open_tabs?.length ?? 0) > 0 && (
                <button type="button" onClick={() => setOpening(t)} className="mt-1 text-left text-xs text-muted underline">+ another bill</button>
              )}
            </div>
          ))}
        </div>
      )}
      {opening && (
        <OpenTabDialog
          table={opening === "walk-up" ? null : opening}
          onClose={() => setOpening(null)}
          onOpened={(id) => {
            setOpening(null);
            setTabId(id);
          }}
        />
      )}
    </>
  );
}

function OpenTabDialog({ table, onClose, onOpened }: { table: BarTable | null; onClose: () => void; onOpened: (id: string) => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({ customer_name: "", customer_phone: "", customer_email: "" });
  const open = useMutation({
    mutationFn: () =>
      barApi.openTab({
        table_id: table?.id ?? null,
        customer_name: f.customer_name.trim() || undefined,
        customer_phone: f.customer_phone.trim() || undefined,
        customer_email: f.customer_email.trim() || undefined,
      }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tables });
      onOpened(res.data.id);
    },
  });
  const err = isApiError(open.error) ? open.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title={table ? `New bill · ${table.name}` : "New bill"}
      description="Customer details are optional. With an email, the bill and a secure pay link are sent after each order."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={open.isPending} onClick={() => open.mutate()}>Open bill</Button>
        </>
      }
    >
      <div className="grid gap-3">
        {open.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(open.error)}</Alert>}
        <Field label="Name"><Input value={f.customer_name} onChange={(e) => setF((x) => ({ ...x, customer_name: e.target.value }))} /></Field>
        <Field label="Phone"><Input type="tel" value={f.customer_phone} onChange={(e) => setF((x) => ({ ...x, customer_phone: e.target.value }))} /></Field>
        <Field label="Email" error={err?.field("customer_email")}><Input type="email" value={f.customer_email} onChange={(e) => setF((x) => ({ ...x, customer_email: e.target.value }))} /></Field>
      </div>
    </Dialog>
  );
}

interface CartLine {
  product: BarProduct;
  quantity: number;
  notes: string;
}

function TabWorkspace({ tabId, onBack }: { tabId: string; onBack: () => void }) {
  const queryClient = useQueryClient();
  const menu = useQuery({ queryKey: barKeys.menu, queryFn: barApi.menu, refetchInterval: 30_000 });
  const tab = useQuery({ queryKey: barKeys.tab(tabId), queryFn: () => barApi.tab(tabId), refetchInterval: 5_000 });
  const [category, setCategory] = useState<number | "all">("all");
  const [search, setSearch] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [note, setNote] = useState("");
  const [editingCustomer, setEditingCustomer] = useState(false);

  const products = useMemo(() => {
    const all = (menu.data ?? []).flatMap((c) => c.products);
    const inCategory = category === "all" ? all : (menu.data?.find((c) => c.id === category)?.products ?? []);
    const term = search.trim().toLowerCase();
    return term ? all.filter((p) => p.name.toLowerCase().includes(term)) : inCategory;
  }, [menu.data, category, search]);

  const cartTotal = cart.reduce((sum, l) => sum + Number(l.product.price) * l.quantity, 0);

  const add = (product: BarProduct) =>
    setCart((c) => {
      const i = c.findIndex((l) => l.product.id === product.id && !l.notes);
      if (i >= 0) return c.map((l, j) => (j === i ? { ...l, quantity: Math.min(99, l.quantity + 1) } : l));
      return [...c, { product, quantity: 1, notes: "" }];
    });

  const send = useMutation({
    mutationFn: () => barApi.placeOrder(tabId, cart.map((l) => ({ product_id: l.product.id, quantity: l.quantity, notes: l.notes.trim() || undefined })), note.trim()),
    onSuccess: async () => {
      setCart([]);
      setNote("");
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tabId) });
      await queryClient.invalidateQueries({ queryKey: barKeys.tables });
    },
  });

  if (tab.isPending) return <LoadingState />;
  if (tab.isError) return <ErrorState error={tab.error} onRetry={() => tab.refetch()} />;
  const t = tab.data;
  const open = t.status === "OPEN";

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Button variant="ghost" onClick={onBack}>← Tables</Button>
        <h1 className="text-2xl font-bold">{t.table?.name ?? "No table"}</h1>
        <span className="font-mono text-sm text-muted">{t.number}</span>
        {!open && <Badge tone="neutral">{t.status === "CLOSED" ? (t.settlement === "CHARGED_TO_ROOM" ? `Charged to room ${t.charged_to?.room ?? ""}` : "Paid & closed") : "Cancelled"}</Badge>}
        <button type="button" className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm hover:bg-surface-muted" onClick={() => setEditingCustomer(true)} disabled={!open}>
          <UserRound className="size-4" aria-hidden="true" /> {t.customer_name || "Add customer"}{t.customer_email ? ` · ${t.customer_email}` : ""}
        </button>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1fr_420px]">
        {open ? (
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <label className="relative min-w-48 flex-1">
                <span className="sr-only">Search menu</span>
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <Input className="h-12 pl-9" placeholder="Search drinks and food" value={search} onChange={(e) => setSearch(e.target.value)} />
              </label>
            </div>
            <div className="flex flex-wrap gap-2" role="tablist" aria-label="Menu categories">
              {[{ id: "all" as const, name: "All" }, ...(menu.data ?? [])].map((c) => (
                <button
                  key={c.id}
                  type="button"
                  role="tab"
                  aria-selected={category === c.id}
                  onClick={() => { setCategory(c.id); setSearch(""); }}
                  className={cn("h-11 rounded-full px-4 text-sm font-semibold", category === c.id ? "bg-brand text-brand-foreground" : "bg-surface-muted")}
                >
                  {c.name}
                </button>
              ))}
            </div>
            {menu.isPending ? (
              <LoadingState />
            ) : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-4">
                {products.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    disabled={!p.is_available}
                    onClick={() => add(p)}
                    className="flex min-h-24 flex-col justify-between rounded-2xl border border-border bg-surface p-3 text-left shadow-sm active:scale-[0.98] disabled:opacity-50"
                  >
                    <span className="font-semibold leading-tight">{p.name}</span>
                    <span className="text-sm text-muted">{p.is_available ? formatNaira(p.price) : "Sold out"}</span>
                  </button>
                ))}
              </div>
            )}

            <Card>
              <CardHeader title="New order" description={cart.length ? `${cart.reduce((n, l) => n + l.quantity, 0)} items` : "Tap items above"} />
              {cart.length > 0 && (
                <CardBody className="space-y-3">
                  {cart.map((l, i) => (
                    <div key={`${l.product.id}-${i}`} className="flex flex-wrap items-center gap-2 text-sm">
                      <span className="min-w-0 flex-1 font-medium">{l.product.name}</span>
                      <Button size="sm" variant="outline" aria-label="Less" onClick={() => setCart((c) => c.map((x, j) => (j === i ? { ...x, quantity: Math.max(1, x.quantity - 1) } : x)))}>
                        <Minus className="size-4" aria-hidden="true" />
                      </Button>
                      <span className="w-6 text-center font-semibold">{l.quantity}</span>
                      <Button size="sm" variant="outline" aria-label="More" onClick={() => setCart((c) => c.map((x, j) => (j === i ? { ...x, quantity: Math.min(99, x.quantity + 1) } : x)))}>
                        <Plus className="size-4" aria-hidden="true" />
                      </Button>
                      <span className="w-24 text-right">{formatNaira((Number(l.product.price) * l.quantity).toFixed(2))}</span>
                      <Button size="sm" variant="ghost" aria-label="Remove" onClick={() => setCart((c) => c.filter((_, j) => j !== i))}>
                        <Trash2 className="size-4" aria-hidden="true" />
                      </Button>
                      <Input className="h-9 w-full text-xs" placeholder="Note for the bar (e.g. no ice)" value={l.notes} maxLength={255}
                        onChange={(e) => setCart((c) => c.map((x, j) => (j === i ? { ...x, notes: e.target.value } : x)))} />
                    </div>
                  ))}
                  <Input placeholder="Note for the whole order" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
                  {send.isError && <Alert tone="danger">{errorMessage(send.error)}</Alert>}
                  <Button size="lg" className="w-full" loading={send.isPending} onClick={() => send.mutate()}>
                    <Send className="size-4" aria-hidden="true" /> Send to bar · {formatNaira(cartTotal.toFixed(2))}
                  </Button>
                  <p className="text-center text-xs text-muted">Service charge and VAT are added on the bill.</p>
                </CardBody>
              )}
            </Card>
          </div>
        ) : (
          <Alert tone="info">This bill is {t.status.toLowerCase()}. Go back to the tables to start a new one.</Alert>
        )}

        <TabBill tab={t} onClosed={onBack} />
      </div>
      {editingCustomer && <CustomerDialog tabId={t.id} initial={t} onClose={() => setEditingCustomer(false)} />}
    </>
  );
}

function CustomerDialog({ tabId, initial, onClose }: { tabId: string; initial: { customer_name: string | null; customer_phone?: string | null; customer_email?: string | null }; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({ customer_name: initial.customer_name ?? "", customer_phone: initial.customer_phone ?? "", customer_email: initial.customer_email ?? "" });
  const save = useMutation({
    mutationFn: () => barApi.updateTab(tabId, {
      customer_name: f.customer_name.trim() || null,
      customer_phone: f.customer_phone.trim() || null,
      customer_email: f.customer_email.trim() || null,
    }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tabId) });
      onClose();
    },
  });
  const err = isApiError(save.error) ? save.error : null;

  return (
    <Dialog open onClose={onClose} title="Customer" footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate()}>Save</Button></>}>
      <div className="grid gap-3">
        {save.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
        <Field label="Name"><Input value={f.customer_name} onChange={(e) => setF((x) => ({ ...x, customer_name: e.target.value }))} /></Field>
        <Field label="Phone"><Input type="tel" value={f.customer_phone} onChange={(e) => setF((x) => ({ ...x, customer_phone: e.target.value }))} /></Field>
        <Field label="Email" error={err?.field("customer_email")}><Input type="email" value={f.customer_email} onChange={(e) => setF((x) => ({ ...x, customer_email: e.target.value }))} /></Field>
      </div>
    </Dialog>
  );
}
