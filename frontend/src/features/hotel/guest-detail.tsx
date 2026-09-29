"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, BadgeCheck, Download, FileUp, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { ReservationStatusBadge } from "@/features/booking/status-badge";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { bffUrl } from "@/lib/api/client";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Guest, GuestDocument } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { DOCUMENT_TYPE_LABELS, hotelApi, hotelKeys } from "./api";

export function GuestDetail({ id }: { id: string }) {
  return (
    <RequirePermission permission="guests.view">
      <Inner id={id} />
    </RequirePermission>
  );
}

function Inner({ id }: { id: string }) {
  const guest = useQuery({ queryKey: hotelKeys.guest(id), queryFn: () => hotelApi.guest(id) });
  return (
    <>
      <Link href="/staff/guests" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> Guests
      </Link>
      {guest.isPending ? <LoadingState /> : guest.isError ? <ErrorState error={guest.error} onRetry={() => guest.refetch()} /> : <Loaded guest={guest.data} />}
    </>
  );
}

function Loaded({ guest }: { guest: Guest }) {
  const { can } = useSession();
  return (
    <>
      <PageHeader
        title={guest.full_name}
        description={[guest.phone, guest.email].filter(Boolean).join(" · ")}
        actions={<>{guest.is_vip && <Badge tone="brand">VIP</Badge>}{guest.has_account && <Badge tone="info">Has online account</Badge>}</>}
      />
      <div className="grid gap-6 xl:grid-cols-2">
        <Profile key={guest.id} guest={guest} editable={can("guests.update")} />
        {can("guests.documents.view") && <Documents guestId={guest.id} />}
        <History guestId={guest.id} />
      </div>
    </>
  );
}

function Profile({ guest, editable }: { guest: Guest; editable: boolean }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    first_name: guest.first_name,
    last_name: guest.last_name,
    phone: guest.phone ?? "",
    email: guest.email ?? "",
    nationality: guest.nationality ?? "",
    date_of_birth: guest.date_of_birth ?? "",
    address: guest.address ?? "",
    company: guest.company ?? "",
    notes: guest.notes ?? "",
    is_vip: guest.is_vip,
  });
  const save = useMutation({
    mutationFn: () =>
      hotelApi.updateGuest(guest.id, {
        ...form,
        phone: form.phone || null,
        email: form.email || null,
        nationality: form.nationality ? form.nationality.toUpperCase() : null,
        date_of_birth: form.date_of_birth || null,
        address: form.address || null,
        company: form.company || null,
        notes: form.notes || null,
      }),
    onSuccess: (res) => queryClient.setQueryData(hotelKeys.guest(guest.id), res.data),
  });
  const err = isApiError(save.error) ? save.error : null;
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <Card>
      <CardHeader title="Profile" />
      <CardBody className="space-y-4">
        {save.isSuccess && <Alert tone="success">{save.data.message}</Alert>}
        {save.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
        <fieldset disabled={!editable} className="grid gap-4 sm:grid-cols-2">
          <Field label="First name" error={err?.field("first_name")}><Input value={form.first_name} onChange={(e) => set("first_name", e.target.value)} /></Field>
          <Field label="Last name" error={err?.field("last_name")}><Input value={form.last_name} onChange={(e) => set("last_name", e.target.value)} /></Field>
          <Field label="Phone" error={err?.field("phone")}><Input type="tel" value={form.phone} onChange={(e) => set("phone", e.target.value)} /></Field>
          <Field label="Email" error={err?.field("email")}><Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} /></Field>
          <Field label="Nationality (2-letter code)" error={err?.field("nationality")}><Input maxLength={2} value={form.nationality} onChange={(e) => set("nationality", e.target.value)} placeholder="NG" /></Field>
          <Field label="Date of birth" error={err?.field("date_of_birth")}><Input type="date" value={form.date_of_birth} onChange={(e) => set("date_of_birth", e.target.value)} /></Field>
          <Field label="Address" className="sm:col-span-2"><Input value={form.address} onChange={(e) => set("address", e.target.value)} /></Field>
          <Field label="Company"><Input value={form.company} onChange={(e) => set("company", e.target.value)} /></Field>
          <Field label="Notes" className="sm:col-span-2"><Input value={form.notes} onChange={(e) => set("notes", e.target.value)} /></Field>
          <Checkbox label="VIP guest" checked={form.is_vip} onChange={(e) => set("is_vip", e.target.checked)} />
        </fieldset>
        {editable && <Button loading={save.isPending} onClick={() => save.mutate()}>Save profile</Button>}
      </CardBody>
    </Card>
  );
}

function Documents({ guestId }: { guestId: string }) {
  const queryClient = useQueryClient();
  const docs = useQuery({ queryKey: hotelKeys.guestDocuments(guestId), queryFn: () => hotelApi.guestDocuments(guestId) });
  const [type, setType] = useState("NATIONAL_ID");
  const [number, setNumber] = useState("");
  const [deleting, setDeleting] = useState<GuestDocument | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const refresh = () => queryClient.invalidateQueries({ queryKey: hotelKeys.guestDocuments(guestId) });

  const upload = useMutation({
    mutationFn: (file: File) => hotelApi.uploadGuestDocument(guestId, { type, number: number || undefined, file }),
    onSuccess: async () => { setNumber(""); await refresh(); },
  });
  const verify = useMutation({ mutationFn: (docId: string) => hotelApi.verifyGuestDocument(guestId, docId), onSuccess: refresh });
  const remove = useMutation({ mutationFn: (docId: string) => hotelApi.deleteGuestDocument(guestId, docId), onSuccess: async () => { setDeleting(null); await refresh(); } });

  return (
    <Card>
      <CardHeader title="Identity documents" description="Stored privately. Every view is recorded in the audit log." />
      <CardBody className="space-y-4">
        {upload.isError && <Alert tone="danger">{errorMessage(upload.error)}</Alert>}
        <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <Field label="Type">
            <Select value={type} onChange={(e) => setType(e.target.value)}>
              {Object.entries(DOCUMENT_TYPE_LABELS).map(([v, l]) => (<option key={v} value={v}>{l}</option>))}
            </Select>
          </Field>
          <Field label="Document number">
            <Input value={number} onChange={(e) => setNumber(e.target.value)} maxLength={50} />
          </Field>
          <div>
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp,application/pdf"
              className="sr-only"
              aria-label="Choose document file"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = ""; }}
            />
            <Button variant="outline" loading={upload.isPending} onClick={() => fileRef.current?.click()}>
              <FileUp className="size-4" aria-hidden="true" /> Upload
            </Button>
          </div>
        </div>
        <p className="text-xs text-muted">JPG, PNG, WebP or PDF, up to 8 MB.</p>
        {docs.isPending ? (
          <LoadingState />
        ) : docs.isError ? (
          <ErrorState error={docs.error} onRetry={() => docs.refetch()} />
        ) : docs.data.length === 0 ? (
          <p className="text-sm text-muted">No documents on file.</p>
        ) : (
          <ul className="divide-y divide-border rounded-lg border border-border text-sm">
            {docs.data.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                <span className="min-w-0 flex-1">
                  <span className="font-medium">{DOCUMENT_TYPE_LABELS[d.type]}</span>
                  {d.number_masked && <span className="ml-2 font-mono text-xs">{d.number_masked}</span>}
                  <span className="block text-xs text-muted">
                    Uploaded {formatDateTime(d.created_at)}{d.uploaded_by ? ` by ${d.uploaded_by}` : ""}
                  </span>
                </span>
                {d.verified_at ? (
                  <Badge tone="success"><BadgeCheck className="size-3" aria-hidden="true" /> Verified</Badge>
                ) : (
                  <Button variant="ghost" size="sm" onClick={() => verify.mutate(d.id)}>Mark verified</Button>
                )}
                <a href={bffUrl(`guests/${guestId}/documents/${d.id}/download`)} className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-medium hover:bg-surface-muted">
                  <Download className="size-4" aria-hidden="true" /> View
                </a>
                <Button variant="ghost" size="sm" aria-label="Delete document" onClick={() => setDeleting(d)}>
                  <Trash2 className="size-4" aria-hidden="true" />
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        loading={remove.isPending}
        title="Delete this document?"
        confirmLabel="Delete"
      >
        The file is removed permanently.
      </ConfirmDialog>
    </Card>
  );
}

function History({ guestId }: { guestId: string }) {
  const history = useQuery({ queryKey: hotelKeys.guestHistory(guestId), queryFn: () => hotelApi.guestHistory(guestId) });
  return (
    <Card className="xl:col-span-2">
      <CardHeader title="Stay history" />
      {history.isPending ? (
        <LoadingState />
      ) : history.isError ? (
        <ErrorState error={history.error} onRetry={() => history.refetch()} />
      ) : history.data.items.length === 0 ? (
        <EmptyState title="No stays yet" />
      ) : (
        <ul className="divide-y divide-border">
          {history.data.items.map((r) => (
            <li key={r.id}>
              <Link href={`/staff/reservations/${r.id}`} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm hover:bg-surface-muted/60">
                <span className="min-w-0 flex-1">
                  <span className="font-semibold">{formatStayDate(r.check_in, true)} → {formatStayDate(r.check_out)}</span>
                  <span className="block text-xs text-muted">{r.number} · {r.rooms?.map((x) => x.room_number ?? x.room_type.name).join(", ")}</span>
                </span>
                <span className="text-xs">{formatNaira(r.total)}</span>
                <ReservationStatusBadge status={r.status} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
