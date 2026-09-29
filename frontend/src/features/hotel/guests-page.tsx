"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Search, Star } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useDeferredValue, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { hotelApi, hotelKeys } from "./api";

export function GuestsPage() {
  return (
    <RequirePermission permission="guests.view">
      <GuestsList />
    </RequirePermission>
  );
}

function GuestsList() {
  const { can } = useSession();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const deferred = useDeferredValue(search);
  const filters = { search: deferred, page };
  const guests = useQuery({ queryKey: hotelKeys.guests(filters), queryFn: () => hotelApi.guests(filters), placeholderData: keepPreviousData });

  return (
    <>
      <PageHeader
        title="Guests"
        actions={can("guests.create") && <Button onClick={() => setCreating(true)}><Plus className="size-4" aria-hidden="true" /> New guest</Button>}
      />
      <Card>
        <div className="border-b border-border p-4">
          <label className="relative block max-w-lg">
            <span className="sr-only">Search guests</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <Input className="pl-9" placeholder="Name, phone, email or reservation number" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </label>
        </div>
        {guests.isPending ? (
          <LoadingState />
        ) : guests.isError ? (
          <ErrorState error={guests.error} onRetry={() => guests.refetch()} />
        ) : guests.data.items.length === 0 ? (
          <EmptyState title="No guests found" />
        ) : (
          <>
            <ul className="divide-y divide-border">
              {guests.data.items.map((g) => (
                <li key={g.id}>
                  <Link href={`/staff/guests/${g.id}`} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm hover:bg-surface-muted/60">
                    <span className="min-w-0 flex-1">
                      <span className="font-semibold">{g.full_name}</span>
                      {g.is_vip && <Star className="ml-1 inline size-4 text-accent" aria-label="VIP" />}
                      <span className="block text-xs text-muted">{[g.phone, g.email].filter(Boolean).join(" · ") || "No contact details"}</span>
                    </span>
                    <span className="text-xs text-muted">{g.reservations_count ?? 0} reservations</span>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination meta={guests.data.meta} onPage={setPage} />
          </>
        )}
      </Card>
      {creating && <CreateGuestDialog onClose={() => setCreating(false)} />}
    </>
  );
}

function CreateGuestDialog({ onClose }: { onClose: () => void }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ first_name: "", last_name: "", phone: "", email: "" });
  const create = useMutation({
    mutationFn: () => hotelApi.createGuest({ ...form, phone: form.phone || null, email: form.email || null }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ["hotel", "guests"] });
      router.push(`/staff/guests/${res.data.id}`);
    },
  });
  const err = isApiError(create.error) ? create.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title="New guest"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={create.isPending} disabled={!form.first_name || !form.last_name} onClick={() => create.mutate()}>Create guest</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {create.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-2">{errorMessage(create.error)}</Alert>}
        <Field label="First name" required error={err?.field("first_name")}><Input value={form.first_name} onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))} /></Field>
        <Field label="Last name" required error={err?.field("last_name")}><Input value={form.last_name} onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))} /></Field>
        <Field label="Phone" error={err?.field("phone")}><Input type="tel" value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} /></Field>
        <Field label="Email" error={err?.field("email")}><Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} /></Field>
      </div>
    </Dialog>
  );
}
