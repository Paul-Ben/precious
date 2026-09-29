"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { hotelKeys } from "@/features/hotel/api";
import { RecordPaymentDialog } from "@/features/payments/reservation-payments";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { CheckOutPreview, Reservation } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { stayApi, stayKeys } from "./api";

export function CheckOutDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const preview = useQuery({ queryKey: stayKeys.checkOut(r.id), queryFn: () => stayApi.checkOutPreview(r.id) });

  return (
    <Dialog open onClose={onClose} title={`Check out ${r.guest?.full_name ?? r.number}`} description={r.number} className="max-w-2xl">
      {preview.isPending ? (
        <LoadingState />
      ) : preview.isError ? (
        <ErrorState error={preview.error} onRetry={() => preview.refetch()} />
      ) : (
        <Body r={r} p={preview.data} onClose={onClose} />
      )}
    </Dialog>
  );
}

function Body({ r, p, onClose }: { r: Reservation; p: CheckOutPreview; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { can } = useSession();
  const [applyLate, setApplyLate] = useState(true);
  const [waiveReason, setWaiveReason] = useState("");
  const [override, setOverride] = useState(false);
  const [overrideReason, setOverrideReason] = useState("");
  const [paying, setPaying] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: stayKeys.checkOut(r.id) });
    await queryClient.invalidateQueries({ queryKey: hotelKeys.reservation(r.id) });
  };

  const checkOut = useMutation({
    mutationFn: () =>
      stayApi.checkOut(r.id, {
        apply_late_fee: applyLate,
        waive_reason: applyLate ? undefined : waiveReason.trim(),
        override_balance: override || undefined,
        override_reason: override ? overrideReason.trim() : undefined,
      }),
    onSuccess: async (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data.reservation);
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      await queryClient.invalidateQueries({ queryKey: ["stays"] });
      setDone(res.data.statement_number);
    },
    // A late fee may have been added even if check-out then stopped for payment.
    onError: refresh,
  });
  const err = isApiError(checkOut.error) ? checkOut.error : null;
  const late = p.late_fee;
  const lateDue = !late.already_charged && Number(late.total) > 0;
  const balance = Number(applyLate ? p.balance_with_late_fee : p.balance_now);

  if (done) {
    return (
      <div className="space-y-4 text-center">
        <p className="text-lg font-semibold">Checked out.</p>
        <p className="text-sm text-muted">Final bill {done} has been issued{r.guest?.email ? ` and emailed to ${r.guest.email}` : ""}. The rooms are marked dirty for housekeeping.</p>
        <div className="flex justify-center gap-2">
          <Link href={`/staff/folios/${done}`} className="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm font-medium hover:bg-surface-muted">View final bill</Link>
          <Button onClick={onClose}>Done</Button>
        </div>
      </div>
    );
  }

  if (p.overstay) {
    return (
      <div className="space-y-3">
        <Alert tone="warning" title="Past the departure date">
          This guest was due to leave on {r.check_out}. Extend the stay to today first so the extra nights are billed, then check out.
        </Alert>
        <div className="flex justify-end"><Button variant="ghost" onClick={onClose}>Close</Button></div>
      </div>
    );
  }

  return (
    <div className="space-y-5 text-sm">
      {p.early_departure && <Alert tone="info">Leaving before the booked departure ({r.check_out}). The remaining nights are released for sale; booked nights stay charged.</Alert>}

      <dl className="space-y-1.5">
        <div className="flex justify-between"><dt className="text-muted">Accommodation ({p.bill.accommodation.nights} nights)</dt><dd>{formatNaira(p.bill.accommodation.total, { kobo: true })}</dd></div>
        {p.bill.charges.map((c) => (
          <div key={c.id} className="flex justify-between"><dt className="text-muted">{c.description}</dt><dd>{formatNaira(c.total, { kobo: true })}</dd></div>
        ))}
        {lateDue && applyLate && (
          <div className="flex justify-between text-warning"><dt>Late check-out ({late.percent}% of a night)</dt><dd>{formatNaira(late.total, { kobo: true })}</dd></div>
        )}
        <div className="flex justify-between"><dt className="text-muted">Paid</dt><dd>−{formatNaira(p.bill.paid, { kobo: true })}</dd></div>
        <div className="flex justify-between border-t border-border pt-2 text-base font-bold"><dt>Balance</dt><dd>{formatNaira(String(balance.toFixed(2)), { kobo: true })}</dd></div>
      </dl>

      {lateDue && (
        <div className="space-y-2 rounded-xl border border-border p-3">
          <p className="font-medium">Leaving after {p.check_out_time}: a late check-out fee of {formatNaira(late.total, { kobo: true })} applies.</p>
          <Checkbox label="Waive the late check-out fee" checked={!applyLate} onChange={(e) => setApplyLate(!e.target.checked)} />
          {!applyLate && (
            <Field label="Reason for waiving" required error={err?.field("waive_reason")}>
              <Input value={waiveReason} maxLength={255} onChange={(e) => setWaiveReason(e.target.value)} />
            </Field>
          )}
        </div>
      )}

      {balance > 0 && (
        <Alert tone="warning" title={`${formatNaira(balance.toFixed(2), { kobo: true })} to pay before check-out`}>
          <div className="mt-2 flex flex-wrap gap-2">
            {can("payments.create") && <Button size="sm" onClick={() => setPaying(true)} disabled={lateDue && applyLate}>Take payment</Button>}
            {lateDue && applyLate && <span className="text-xs">Press “Check out” once to add the late fee to the bill, then take payment.</span>}
          </div>
        </Alert>
      )}

      {balance > 0 && p.can_override_balance && (
        <div className="space-y-2 rounded-xl border border-border p-3">
          <Checkbox label="Check out with the balance unpaid (manager override)" description="E.g. a company that settles by invoice. Recorded in the audit log." checked={override} onChange={(e) => setOverride(e.target.checked)} />
          {override && (
            <Field label="Reason" required error={err?.field("override_reason")}>
              <Input value={overrideReason} maxLength={255} onChange={(e) => setOverrideReason(e.target.value)} />
            </Field>
          )}
        </div>
      )}

      {checkOut.isError && !err?.isValidation && <Alert tone={err?.code === "BALANCE_DUE" ? "warning" : "danger"}>{errorMessage(checkOut.error)}</Alert>}

      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button
          loading={checkOut.isPending}
          disabled={(!applyLate && waiveReason.trim().length < 3) || (override && overrideReason.trim().length < 5)}
          onClick={() => checkOut.mutate()}
        >
          Check out
        </Button>
      </div>

      {paying && (
        <RecordPaymentDialog
          r={{ ...r, balance: p.balance_now }}
          onClose={() => {
            setPaying(false);
            void refresh();
          }}
        />
      )}
    </div>
  );
}
