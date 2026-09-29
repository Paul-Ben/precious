"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog, Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { BarCategory, BarProduct, BarTable } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { barApi, barKeys } from "./api";

export function BarSetupPage() {
  return (
    <RequirePermission permission={["bar.products.manage", "bar.tables.manage"]}>
      <Setup />
    </RequirePermission>
  );
}

function Setup() {
  const { can } = useSession();
  const [tab, setTab] = useState<"menu" | "tables">(can("bar.products.manage") ? "menu" : "tables");

  return (
    <>
      <PageHeader title="Bar setup" description="Menu prices are before service charge (10%) and VAT (7.5%), which are added on the bill." />
      <div role="tablist" className="mb-4 inline-flex rounded-lg bg-surface-muted p-1">
        {(["menu", "tables"] as const).map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={cn("rounded-md px-4 py-2 text-sm font-medium", tab === t ? "bg-surface shadow-sm" : "text-muted")}>
            {t === "menu" ? "Menu" : "Tables"}
          </button>
        ))}
      </div>
      {tab === "menu" ? <MenuSetup /> : <TablesSetup />}
    </>
  );
}

// -------------------------------------------------------------------- Menu

function MenuSetup() {
  const queryClient = useQueryClient();
  const categories = useQuery({ queryKey: barKeys.categories, queryFn: barApi.categories });
  const products = useQuery({ queryKey: barKeys.products, queryFn: barApi.products });
  const [newCategory, setNewCategory] = useState("");
  const [editing, setEditing] = useState<BarProduct | "new" | null>(null);
  const [deleting, setDeleting] = useState<BarProduct | null>(null);
  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ["bar"] });
  };

  const addCategory = useMutation({ mutationFn: () => barApi.createCategory(newCategory.trim()), onSuccess: async () => { setNewCategory(""); await refresh(); } });
  const toggleCategory = useMutation({ mutationFn: (c: BarCategory) => barApi.updateCategory(c.id, { is_active: !c.is_active }), onSuccess: refresh });
  const deleteCategory = useMutation({ mutationFn: (id: number) => barApi.deleteCategory(id), onSuccess: refresh });
  const removeProduct = useMutation({ mutationFn: (id: number) => barApi.deleteProduct(id), onSuccess: async () => { setDeleting(null); await refresh(); } });
  const availability = useMutation({ mutationFn: (p: BarProduct) => barApi.setAvailability(p.id, !p.is_available), onSuccess: refresh });

  return (
    <div className="grid gap-6 xl:grid-cols-[320px_1fr]">
      <Card>
        <CardHeader title="Categories" />
        <CardBody className="space-y-3">
          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (newCategory.trim()) addCategory.mutate(); }}>
            <Input placeholder="e.g. Cocktails" value={newCategory} maxLength={80} onChange={(e) => setNewCategory(e.target.value)} />
            <Button type="submit" loading={addCategory.isPending} disabled={!newCategory.trim()}>Add</Button>
          </form>
          {(addCategory.isError || deleteCategory.isError) && <Alert tone="danger">{errorMessage(addCategory.error ?? deleteCategory.error)}</Alert>}
          <ul className="divide-y divide-border text-sm">
            {categories.data?.map((c) => (
              <li key={c.id} className="flex items-center gap-2 py-2">
                <span className={cn("flex-1 font-medium", !c.is_active && "text-muted line-through")}>{c.name}</span>
                <span className="text-xs text-muted">{c.products_count ?? 0}</span>
                <Button size="sm" variant="ghost" onClick={() => toggleCategory.mutate(c)}>{c.is_active ? "Hide" : "Show"}</Button>
                <Button size="sm" variant="ghost" onClick={() => deleteCategory.mutate(c.id)}>Delete</Button>
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Products" actions={<Button size="sm" disabled={!categories.data?.length} onClick={() => setEditing("new")}><Plus className="size-4" aria-hidden="true" /> New product</Button>} />
        {products.isPending ? (
          <LoadingState />
        ) : products.isError ? (
          <ErrorState error={products.error} onRetry={() => products.refetch()} />
        ) : products.data.length === 0 ? (
          <EmptyState title="No products yet">Add a category first, then products.</EmptyState>
        ) : (
          <ul className="divide-y divide-border text-sm">
            {products.data.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="font-semibold">{p.name}</span> {!p.is_active && <Badge tone="neutral">Hidden</Badge>} {!p.is_available && <Badge tone="warning">Sold out</Badge>}
                  <span className="block text-xs text-muted">{p.category}{p.description ? ` · ${p.description}` : ""}</span>
                </span>
                <span className="font-semibold">{formatNaira(p.price)}</span>
                <Button size="sm" variant="ghost" onClick={() => availability.mutate(p)}>{p.is_available ? "Sold out" : "Back on"}</Button>
                <Button size="sm" variant="ghost" onClick={() => setEditing(p)}>Edit</Button>
                <Button size="sm" variant="ghost" onClick={() => setDeleting(p)}>Remove</Button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {editing && <ProductDialog key={editing === "new" ? "new" : editing.id} product={editing === "new" ? null : editing} categories={categories.data ?? []} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && removeProduct.mutate(deleting.id)} loading={removeProduct.isPending} title={`Remove ${deleting?.name}?`} confirmLabel="Remove">
        Past bills keep their lines.
      </ConfirmDialog>
    </div>
  );
}

function ProductDialog({ product, categories, onClose }: { product: BarProduct | null; categories: BarCategory[]; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({
    category_id: product?.category_id ?? categories[0]?.id ?? 0,
    name: product?.name ?? "",
    description: product?.description ?? "",
    price: product?.price ?? "",
    is_active: product?.is_active ?? true,
  });
  const save = useMutation({
    mutationFn: () => {
      const body = { ...f, description: f.description || null, price: f.price.trim() };
      return product ? barApi.updateProduct(product.id, body) : barApi.createProduct(body);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["bar"] });
      onClose();
    },
  });
  const err = isApiError(save.error) ? save.error : null;

  return (
    <Dialog open onClose={onClose} title={product ? `Edit ${product.name}` : "New product"}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={save.isPending} disabled={!f.name.trim() || !f.price} onClick={() => save.mutate()}>Save</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        {save.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-2">{errorMessage(save.error)}</Alert>}
        <Field label="Name" required error={err?.field("name")}><Input value={f.name} maxLength={120} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} /></Field>
        <Field label="Category" required>
          <Select value={f.category_id} onChange={(e) => setF((x) => ({ ...x, category_id: Number(e.target.value) }))}>
            {categories.map((c) => (<option key={c.id} value={c.id}>{c.name}</option>))}
          </Select>
        </Field>
        <Field label="Price (₦, before tax)" required error={err?.field("price")}><Input inputMode="decimal" value={f.price} onChange={(e) => setF((x) => ({ ...x, price: e.target.value.replace(/[^\d.]/g, "") }))} /></Field>
        <Field label="Description"><Input value={f.description} maxLength={500} onChange={(e) => setF((x) => ({ ...x, description: e.target.value }))} /></Field>
        <Checkbox label="On the menu" checked={f.is_active} onChange={(e) => setF((x) => ({ ...x, is_active: e.target.checked }))} />
      </div>
    </Dialog>
  );
}

// ------------------------------------------------------------------ Tables

function TablesSetup() {
  const queryClient = useQueryClient();
  const tables = useQuery({ queryKey: [...barKeys.tables, "all"], queryFn: () => barApi.tables(true) });
  const [editing, setEditing] = useState<BarTable | "new" | null>(null);
  const remove = useMutation({ mutationFn: (id: number) => barApi.deleteTable(id), onSuccess: () => queryClient.invalidateQueries({ queryKey: ["bar"] }) });
  const status = useMutation({
    mutationFn: ({ id, value }: { id: number; value: BarTable["status"] }) => barApi.setTableStatus(id, value),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["bar"] }),
  });

  return (
    <Card>
      <CardHeader title="Tables" actions={<Button size="sm" onClick={() => setEditing("new")}><Plus className="size-4" aria-hidden="true" /> New table</Button>} />
      {(status.isError || remove.isError) && <Alert tone="danger" className="mx-5">{errorMessage(status.error ?? remove.error)}</Alert>}
      {tables.isPending ? (
        <LoadingState />
      ) : tables.isError ? (
        <ErrorState error={tables.error} onRetry={() => tables.refetch()} />
      ) : tables.data.length === 0 ? (
        <EmptyState title="No tables yet" />
      ) : (
        <ul className="divide-y divide-border text-sm">
          {tables.data.map((t) => (
            <li key={t.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5">
              <span className={cn("min-w-0 flex-1 font-semibold", !t.is_active && "text-muted line-through")}>
                {t.name} <span className="font-normal text-muted">· {t.capacity} seats{t.area ? ` · ${t.area}` : ""}</span>
              </span>
              <Select aria-label={`Status of ${t.name}`} className="w-36" value={t.status} onChange={(e) => status.mutate({ id: t.id, value: e.target.value as BarTable["status"] })}>
                {["AVAILABLE", "OCCUPIED", "RESERVED", "CLEANING", "BLOCKED"].map((s) => (<option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>))}
              </Select>
              <Button size="sm" variant="ghost" onClick={() => setEditing(t)}>Edit</Button>
              <Button size="sm" variant="ghost" onClick={() => remove.mutate(t.id)}>Remove</Button>
            </li>
          ))}
        </ul>
      )}
      {editing && <TableDialog key={editing === "new" ? "new" : editing.id} table={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}

function TableDialog({ table, onClose }: { table: BarTable | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({ name: table?.name ?? "", capacity: String(table?.capacity ?? 4), area: table?.area ?? "", is_active: table?.is_active ?? true });
  const save = useMutation({
    mutationFn: () => {
      const body = { name: f.name.trim(), capacity: Number(f.capacity) || 1, area: f.area.trim() || null, is_active: f.is_active };
      return table ? barApi.updateTable(table.id, body) : barApi.createTable(body);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["bar"] });
      onClose();
    },
  });
  const err = isApiError(save.error) ? save.error : null;

  return (
    <Dialog open onClose={onClose} title={table ? `Edit ${table.name}` : "New table"}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={save.isPending} disabled={!f.name.trim()} onClick={() => save.mutate()}>Save</Button></>}>
      <div className="grid gap-3 sm:grid-cols-3">
        {save.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-3">{errorMessage(save.error)}</Alert>}
        <Field label="Name" required error={err?.field("name")}><Input value={f.name} maxLength={40} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} /></Field>
        <Field label="Seats"><Input inputMode="numeric" value={f.capacity} onChange={(e) => setF((x) => ({ ...x, capacity: e.target.value.replace(/\D/g, "") }))} /></Field>
        <Field label="Area"><Input value={f.area} maxLength={60} placeholder="Lounge, Terrace…" onChange={(e) => setF((x) => ({ ...x, area: e.target.value }))} /></Field>
        <Checkbox label="In use" checked={f.is_active} onChange={(e) => setF((x) => ({ ...x, is_active: e.target.checked }))} />
      </div>
    </Dialog>
  );
}
