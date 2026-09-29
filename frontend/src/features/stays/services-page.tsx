"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog, Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { HotelService } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { stayApi, stayKeys } from "./api";

export function ServicesPage() {
  return (
    <RequirePermission permission={["services.view", "services.manage"]}>
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const { can } = useSession();
  const q = useQuery({ queryKey: stayKeys.services, queryFn: () => stayApi.services() });
  const [editing, setEditing] = useState<HotelService | "new" | null>(null);
  const [deleting, setDeleting] = useState<HotelService | null>(null);
  const queryClient = useQueryClient();
  const remove = useMutation({
    mutationFn: (id: number) => stayApi.deleteService(id),
    onSuccess: async () => {
      setDeleting(null);
      await queryClient.invalidateQueries({ queryKey: stayKeys.services });
    },
  });
  const manage = can("services.manage");

  return (
    <>
      <PageHeader
        title="Hotel services"
        description="Price list for extras added to a guest's bill (laundry, room service, transfers…). Service charge and VAT use the rates in Property & policies."
        actions={manage && <Button onClick={() => setEditing("new")}><Plus className="size-4" aria-hidden="true" /> New service</Button>}
      />
      <Card>
        {q.isPending ? (
          <LoadingState />
        ) : q.isError ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.data.items.length === 0 ? (
          <EmptyState title="No services yet" />
        ) : (
          <ul className="divide-y divide-border">
            {q.data.items.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">{s.name} {!s.is_active && <Badge tone="neutral">Inactive</Badge>}</p>
                  <p className="text-xs text-muted">
                    {s.category}
                    {s.charges_service_charge ? " · service charge" : ""}
                    {s.charges_vat ? " · VAT" : ""}
                    {s.description ? ` · ${s.description}` : ""}
                  </p>
                </div>
                <span className="font-semibold">{formatNaira(s.price)}</span>
                {manage && (
                  <>
                    <Button size="sm" variant="ghost" onClick={() => setEditing(s)}>Edit</Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(s)}>Remove</Button>
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
      {editing && (
        <ServiceDialog key={editing === "new" ? "new" : editing.id} service={editing === "new" ? null : editing} categories={q.data?.categories ?? []} onClose={() => setEditing(null)} />
      )}
      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        loading={remove.isPending}
        title={`Remove ${deleting?.name}?`}
        confirmLabel="Remove"
      >
        It disappears from the list; bills that already include it are unchanged.
      </ConfirmDialog>
    </>
  );
}

function ServiceDialog({ service, categories, onClose }: { service: HotelService | null; categories: string[]; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({
    name: service?.name ?? "",
    category: service?.category ?? categories[0] ?? "Other",
    description: service?.description ?? "",
    price: service?.price ?? "",
    charges_vat: service?.charges_vat ?? true,
    charges_service_charge: service?.charges_service_charge ?? true,
    is_active: service?.is_active ?? true,
  });
  const save = useMutation({
    mutationFn: () => {
      const body = { ...f, description: f.description || null, price: f.price.trim() };
      return service ? stayApi.updateService(service.id, body) : stayApi.createService(body);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: stayKeys.services });
      onClose();
    },
  });
  const err = isApiError(save.error) ? save.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title={service ? `Edit ${service.name}` : "New service"}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={save.isPending} disabled={!f.name.trim() || !f.price} onClick={() => save.mutate()}>Save</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {save.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-2">{errorMessage(save.error)}</Alert>}
        <Field label="Name" required error={err?.field("name")}><Input value={f.name} maxLength={120} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} /></Field>
        <Field label="Category" required error={err?.field("category")}>
          <Input list="service-categories" value={f.category} maxLength={40} onChange={(e) => setF((x) => ({ ...x, category: e.target.value }))} />
        </Field>
        <datalist id="service-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
        <Field label="Price (₦, before tax)" required error={err?.field("price")}>
          <Input inputMode="decimal" value={f.price} onChange={(e) => setF((x) => ({ ...x, price: e.target.value.replace(/[^\d.]/g, "") }))} />
        </Field>
        <Field label="Description"><Input value={f.description} maxLength={500} onChange={(e) => setF((x) => ({ ...x, description: e.target.value }))} /></Field>
        <Checkbox label="Add service charge" checked={f.charges_service_charge} onChange={(e) => setF((x) => ({ ...x, charges_service_charge: e.target.checked }))} />
        <Checkbox label="Add VAT" checked={f.charges_vat} onChange={(e) => setF((x) => ({ ...x, charges_vat: e.target.checked }))} />
        <Checkbox label="Active" checked={f.is_active} onChange={(e) => setF((x) => ({ ...x, is_active: e.target.checked }))} />
      </div>
    </Dialog>
  );
}
