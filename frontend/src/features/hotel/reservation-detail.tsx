"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { PaymentStatusBadge, ReservationStatusBadge } from "@/features/booking/status-badge";
import { ReservationPayments } from "@/features/payments/reservation-payments";
import { CheckInDialog } from "@/features/stays/check-in-dialog";
import { CheckOutDialog } from "@/features/stays/check-out-dialog";
import { BillCard, ExtendDialog, StaysCard } from "@/features/stays/stay-panels";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { Reservation } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira, formatPercent } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { hotelApi, hotelKeys } from "./api";

export type DeskAction = "check-in" | "check-out" | null;

export function ReservationDetail({ id, action = null }: { id: string; action?: DeskAction }) {
  return (
    <RequirePermission permission="reservations.view">
      <Inner id={id} action={action} />
    </RequirePermission>
  );
}

function Inner({ id, action }: { id: string; action: DeskAction }) {
  const q = useQuery({ queryKey: hotelKeys.reservation(id), queryFn: () => hotelApi.reservation(id) });

  return (
    <>
      <Link href="/staff/reservations" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> Reservations
      </Link>
      {q.isPending ? <LoadingState /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : <Loaded r={q.data} action={action} />}
    </>
  );
}

function Loaded({ r, action }: { r: Reservation; action: DeskAction }) {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const [cancelOpen, setCancelOpen] = useState(false);
  const [desk, setDesk] = useState<"check-in" | "check-out" | "extend" | null>(() =>
    action === "check-in" && r.status === "CONFIRMED" ? "check-in" : action === "check-out" && r.status === "CHECKED_IN" ? "check-out" : null,
  );
  const [notes, setNotes] = useState({ special_requests: r.special_requests ?? "", internal_notes: r.internal_notes ?? "" });
  const [saved, setSaved] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => hotelApi.updateReservation(r.id, notes),
    onSuccess: (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data);
      setSaved(res.message);
    },
  });

  const cancellable = ["PENDING_PAYMENT", "CONFIRMED", "PARTIALLY_PAID"].includes(r.status);

  return (
    <>
      <PageHeader
        title={r.guest?.full_name ?? r.number}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-mono">{r.number}</span> <ReservationStatusBadge status={r.status} /> <PaymentStatusBadge status={r.payment_status} />
          </span>
        }
        actions={
          <>
            {can("checkins.create") && r.status === "CONFIRMED" && <Button onClick={() => setDesk("check-in")}>Check in</Button>}
            {can("checkouts.create") && r.status === "CHECKED_IN" && <Button onClick={() => setDesk("check-out")}>Check out</Button>}
            {can("reservations.update") && ["CONFIRMED", "CHECKED_IN"].includes(r.status) && (
              <Button variant="outline" onClick={() => setDesk("extend")}>Extend stay</Button>
            )}
            {r.folio_number && (
              <Link href={`/staff/folios/${r.folio_number}`} className="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm font-medium hover:bg-surface-muted">
                Final bill {r.folio_number}
              </Link>
            )}
            {can("reservations.cancel") && cancellable && (
              <Button variant="danger" onClick={() => setCancelOpen(true)}>
                Cancel reservation
              </Button>
            )}
          </>
        }
      />

      {r.status === "PENDING_PAYMENT" && r.expires_at && (
        <Alert tone="warning" className="mb-4">
          Rooms are held until {formatDateTime(r.expires_at)}. A payment of at least the deposit confirms the reservation.
        </Alert>
      )}
      {r.cancellation && (
        <Alert tone="danger" className="mb-4" title={`Cancelled ${formatDateTime(r.cancellation.cancelled_at)}`}>
          {r.cancellation.reason}
          {r.cancellation.refund_eligible !== null && (r.cancellation.refund_eligible ? " · Eligible for refund of payments." : " · Outside the free-cancellation window.")}
        </Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
        <div className="space-y-6">
          <Card>
            <CardHeader title={`${formatStayDate(r.check_in, true)} → ${formatStayDate(r.check_out, true)}`} description={`${r.nights} nights · ${r.adults} adults, ${r.children} children · via ${r.source.replace("_", " ").toLowerCase()}`} />
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th className="px-5 py-2 font-medium">Room</th>
                    <th className="px-3 py-2 font-medium">Type</th>
                    <th className="px-3 py-2 font-medium">Guests</th>
                    <th className="px-3 py-2 font-medium">Rate</th>
                    <th className="px-5 py-2 text-right font-medium">Subtotal</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {r.rooms?.map((room) => (
                    <tr key={room.id} className={room.is_active ? undefined : "text-muted line-through"}>
                      <td className="px-5 py-2 font-semibold">{room.room_number}</td>
                      <td className="px-3 py-2">{room.room_type.name}</td>
                      <td className="px-3 py-2">{room.adults} + {room.children}</td>
                      <td className="px-3 py-2">{formatNaira(room.nightly_rate)} × {room.nights}</td>
                      <td className="px-5 py-2 text-right">{formatNaira(room.subtotal)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <StaysCard r={r} />

          <Card>
            <CardHeader title="Guests" />
            <CardBody className="space-y-2 text-sm">
              {r.guests?.map((g) => (
                <p key={g.id}>
                  <Link href={`/staff/guests/${g.id}`} className="font-medium hover:underline">{g.full_name}</Link>
                  {g.is_primary && <span className="ml-2 text-xs text-muted">booking guest · {[r.guest?.phone, r.guest?.email].filter(Boolean).join(" · ")}</span>}
                </p>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Notes" />
            <CardBody className="space-y-4">
              {saved && <Alert tone="success">{saved}</Alert>}
              {save.isError && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
              <Field label="Guest requests">
                <Input value={notes.special_requests} disabled={!can("reservations.update")} onChange={(e) => setNotes((n) => ({ ...n, special_requests: e.target.value }))} />
              </Field>
              <Field label="Internal notes (staff only)">
                <Input value={notes.internal_notes} disabled={!can("reservations.update")} onChange={(e) => setNotes((n) => ({ ...n, internal_notes: e.target.value }))} />
              </Field>
              {can("reservations.update") && (
                <Button variant="outline" loading={save.isPending} onClick={() => { setSaved(null); save.mutate(); }}>
                  Save notes
                </Button>
              )}
            </CardBody>
          </Card>
        </div>

        <aside className="space-y-6">
          <BillCard r={r} />
          <Card>
            <CardHeader title="Accommodation" />
            <CardBody className="text-sm">
              <dl className="space-y-1.5">
                <div className="flex justify-between"><dt className="text-muted">Accommodation</dt><dd>{formatNaira(r.subtotal)}</dd></div>
                {r.service_charge_total !== "0.00" && <div className="flex justify-between"><dt className="text-muted">Service charge</dt><dd>{formatNaira(r.service_charge_total)}</dd></div>}
                <div className="flex justify-between"><dt className="text-muted">VAT ({formatPercent(r.pricing?.vat_percent)})</dt><dd>{formatNaira(r.tax_total)}</dd></div>
                <div className="flex justify-between border-t border-border pt-2 font-bold"><dt>Total</dt><dd>{formatNaira(r.total)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Deposit required ({formatPercent(r.deposit_percent)})</dt><dd>{formatNaira(r.deposit_amount)}</dd></div>
              </dl>
            </CardBody>
          </Card>
          <ReservationPayments r={r} />
          <Card>
            <CardBody className="space-y-1 text-xs text-muted">
              <p>Created {formatDateTime(r.created_at)}{r.booked_by ? ` by ${r.booked_by.name}` : " online"}</p>
              <p>Free cancellation until {r.free_cancellation_hours}h before check-in</p>
              {can("audit.view") && (
                <Link href={`/staff/audit-logs?auditable_type=reservation&auditable_id=${r.id}`} className="font-medium text-foreground underline underline-offset-4">
                  View audit trail
                </Link>
              )}
            </CardBody>
          </Card>
        </aside>
      </div>

      {cancelOpen && <CancelDialog r={r} onClose={() => setCancelOpen(false)} />}
      {desk === "check-in" && <CheckInDialog r={r} onClose={() => setDesk(null)} />}
      {desk === "check-out" && <CheckOutDialog r={r} onClose={() => setDesk(null)} />}
      {desk === "extend" && <ExtendDialog r={r} onClose={() => setDesk(null)} />}
    </>
  );
}

function CancelDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState("");
  const cancel = useMutation({
    mutationFn: () => hotelApi.cancelReservation(r.id, reason),
    onSuccess: async (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data);
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      onClose();
    },
  });

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Cancel ${r.number}?`}
      description="The rooms are released immediately. This can't be undone."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Keep</Button>
          <Button variant="danger" loading={cancel.isPending} disabled={reason.trim().length < 3} onClick={() => cancel.mutate()}>
            Cancel reservation
          </Button>
        </>
      }
    >
      {cancel.isError && <Alert tone="danger" className="mb-3">{errorMessage(cancel.error)}</Alert>}
      <Field label="Reason" required>
        <Input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
      </Field>
    </Dialog>
  );
}
