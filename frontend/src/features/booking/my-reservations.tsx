"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { PayPanel } from "@/features/payments/pay-panel";
import { errorMessage } from "@/lib/api/errors";
import type { Reservation } from "@/lib/api/types";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { bookingApi, bookingKeys } from "./api";
import { PaymentStatusBadge, ReservationStatusBadge } from "./status-badge";

export function MyReservations() {
  const list = useQuery({ queryKey: bookingKeys.myReservations, queryFn: bookingApi.myReservations });
  const [cancelling, setCancelling] = useState<Reservation | null>(null);
  const [paying, setPaying] = useState<string | null>(null);

  return (
    <Card>
      <CardHeader
        title="Your reservations"
        actions={
          <Link href="/rooms" className="text-sm font-semibold underline-offset-4 hover:underline">
            Book a stay
          </Link>
        }
      />
      {list.isPending ? (
        <LoadingState />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.items.length === 0 ? (
        <EmptyState title="No reservations yet" action={<Link href="/rooms" className="text-sm font-semibold underline">Find a room</Link>} />
      ) : (
        <ul className="divide-y divide-border">
          {list.data.items.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-4 text-sm">
              <div className="min-w-0 flex-1">
                <p className="font-semibold">
                  {formatStayDate(r.check_in, true)} → {formatStayDate(r.check_out)}{" "}
                  <span className="font-mono text-xs font-normal text-muted">{r.number}</span>
                </p>
                <p className="text-muted">
                  {r.rooms?.map((x) => x.room_type.name).join(", ")} · {formatNaira(r.total)}
                </p>
              </div>
              <ReservationStatusBadge status={r.status} />
              <PaymentStatusBadge status={r.payment_status} />
              {["PENDING_PAYMENT", "CONFIRMED", "CHECKED_IN"].includes(r.status) && Number(r.balance) > 0 && (
                <Button variant={paying === r.id ? "ghost" : "outline"} size="sm" onClick={() => setPaying(paying === r.id ? null : r.id)}>
                  {paying === r.id ? "Close" : r.status === "PENDING_PAYMENT" ? "Pay to confirm" : "Pay balance"}
                </Button>
              )}
              {["PENDING_PAYMENT", "CONFIRMED"].includes(r.status) && (
                <Button variant="ghost" size="sm" onClick={() => setCancelling(r)}>
                  Cancel
                </Button>
              )}
              {paying === r.id && (
                <div className="w-full pt-2">
                  <PayPanel payer={{ kind: "customer", id: r.id }} title={`Pay for ${r.number}`} />
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {cancelling && <CancelDialog reservation={cancelling} onClose={() => setCancelling(null)} />}
    </Card>
  );
}

function CancelDialog({ reservation, onClose }: { reservation: Reservation; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState("");
  const cancel = useMutation({
    mutationFn: () => bookingApi.cancelMine(reservation.id, reason),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: bookingKeys.myReservations });
      onClose();
    },
  });

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Cancel ${reservation.number}?`}
      description={`Free cancellation up to ${reservation.free_cancellation_hours} hours before check-in. Refunds of any payment are processed by the hotel.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Keep reservation
          </Button>
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
