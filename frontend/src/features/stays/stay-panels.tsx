"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowRightLeft, Plus, X } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { hotelApi, hotelKeys } from "@/features/hotel/api";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Charge, Reservation, Stay } from "@/lib/api/types";
import { addDays, formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { type AddChargeBody, stayApi, stayKeys } from "./api";

/** Rooms the guest has occupied, with room moves. */
export function StaysCard({ r }: { r: Reservation }) {
  const { can } = useSession();
  const [moving, setMoving] = useState<Stay | null>(null);
  const stays = r.stays ?? [];
  if (stays.length === 0) return null;

  return (
    <Card>
      <CardHeader title="Stay" description={r.checked_in_at ? `Checked in ${formatDateTime(r.checked_in_at)}` : undefined} />
      <ul className="divide-y divide-border text-sm">
        {stays.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
            <span className="font-semibold">Room {s.room?.number}</span>
            <Badge tone={s.status === "OPEN" ? "success" : "neutral"}>
              {s.status === "OPEN" ? "In house" : s.close_reason === "MOVED" ? "Moved out" : "Checked out"}
            </Badge>
            <span className="min-w-0 flex-1 text-xs text-muted">
              {formatDateTime(s.checked_in_at)}{s.closed_at ? ` → ${formatDateTime(s.closed_at)}` : ""}
              {s.checked_in_by ? ` · by ${s.checked_in_by}` : ""}
              {s.id_type ? ` · ${s.id_type.replace("_", " ").toLowerCase()} ${s.id_number_masked ?? ""}` : ""}
            </span>
            {s.status === "OPEN" && can("checkins.create") && (
              <Button size="sm" variant="ghost" onClick={() => setMoving(s)}>
                <ArrowRightLeft className="size-4" aria-hidden="true" /> Move
              </Button>
            )}
          </li>
        ))}
      </ul>
      {moving && <MoveDialog r={r} stay={moving} onClose={() => setMoving(null)} />}
    </Card>
  );
}

function MoveDialog({ r, stay, onClose }: { r: Reservation; stay: Stay; onClose: () => void }) {
  const queryClient = useQueryClient();
  const from = todayInHotel() > r.check_in ? todayInHotel() : r.check_in;
  const free = useQuery({
    queryKey: hotelKeys.deskAvailability(from, r.check_out),
    queryFn: () => hotelApi.deskAvailability(from, r.check_out),
    enabled: from < r.check_out,
  });
  const [roomId, setRoomId] = useState<number | "">("");
  const [reason, setReason] = useState("");
  const move = useMutation({
    mutationFn: () => stayApi.move(stay.id, Number(roomId), reason.trim()),
    onSuccess: async (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data);
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      onClose();
    },
  });

  const rooms = (free.data?.room_types ?? []).flatMap((t) => t.available_rooms.map((room) => ({ ...room, type: t.name })));

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Move from room ${stay.room?.number}`}
      description={`From ${formatStayDate(from, true)} to ${formatStayDate(r.check_out, true)}. The old room is marked dirty.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={move.isPending} disabled={!roomId || reason.trim().length < 3} onClick={() => move.mutate()}>Move guest</Button>
        </>
      }
    >
      <div className="space-y-4">
        {move.isError && <Alert tone="danger">{errorMessage(move.error)}</Alert>}
        <Field label="New room" required>
          <Select value={roomId} onChange={(e) => setRoomId(e.target.value ? Number(e.target.value) : "")}>
            <option value="">{free.isPending ? "Loading free rooms…" : "Choose a free room"}</option>
            {rooms.map((room) => (<option key={room.id} value={room.id}>Room {room.number} · {room.type}</option>))}
          </Select>
        </Field>
        <Field label="Reason" required>
          <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Air conditioning fault" />
        </Field>
      </div>
    </Dialog>
  );
}

export function ExtendDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [date, setDate] = useState(addDays(r.check_out, 1));
  const extend = useMutation({
    mutationFn: () => stayApi.extend(r.id, date),
    onSuccess: async (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data);
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      onClose();
    },
  });
  const nightly = r.rooms?.filter((x) => x.is_active).reduce((sum, x) => sum + Number(x.nightly_rate), 0) ?? 0;
  const extra = Math.max(0, Math.round((Date.parse(date) - Date.parse(r.check_out)) / 86_400_000));

  return (
    <Dialog
      open
      onClose={onClose}
      title="Extend the stay"
      description={`Currently leaving ${formatStayDate(r.check_out, true)}. Extra nights are billed at the booked rate plus VAT.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={extend.isPending} disabled={extra < 1} onClick={() => extend.mutate()}>Extend by {extra} night{extra === 1 ? "" : "s"}</Button>
        </>
      }
    >
      <div className="space-y-4">
        {extend.isError && <Alert tone="danger">{errorMessage(extend.error)}</Alert>}
        <Field label="New departure date" hint={extra > 0 ? `About ${formatNaira((nightly * extra).toFixed(2))} before tax` : undefined}>
          <Input type="date" min={addDays(r.check_out, 1)} value={date} onChange={(e) => setDate(e.target.value)} />
        </Field>
      </div>
    </Dialog>
  );
}

/** The guest bill: booked accommodation, extras, payments and balance. */
export function BillCard({ r }: { r: Reservation }) {
  const { can } = useSession();
  const [adding, setAdding] = useState(false);
  const [voiding, setVoiding] = useState<Charge | null>(null);
  const inHouse = r.status === "CHECKED_IN";
  const charges = r.charges ?? [];

  return (
    <Card>
      <CardHeader
        title="Bill"
        actions={inHouse && (can("services.charge") || can("bills.update")) && (
          <Button size="sm" variant="outline" onClick={() => setAdding(true)}><Plus className="size-4" aria-hidden="true" /> Add charge</Button>
        )}
      />
      <CardBody className="text-sm">
        <dl className="space-y-1.5">
          <div className="flex justify-between"><dt className="text-muted">Accommodation (incl. tax)</dt><dd>{formatNaira(r.total, { kobo: true })}</dd></div>
          {charges.map((c) => (
            <div key={c.id} className={`flex items-start justify-between gap-2 ${c.status === "VOIDED" ? "text-muted line-through" : ""}`}>
              <dt className="min-w-0">
                {c.description}
                {Number(c.quantity) !== 1 && ` × ${Number(c.quantity)}`}
                <span className="block text-xs text-muted no-underline">
                  {formatDateTime(c.created_at)}{c.created_by ? ` · ${c.created_by}` : ""}
                  {Number(c.service_charge) > 0 && ` · SC ${formatNaira(c.service_charge, { kobo: true })}`}
                  {Number(c.vat) > 0 && ` · VAT ${formatNaira(c.vat, { kobo: true })}`}
                  {c.status === "VOIDED" && ` · voided: ${c.void_reason}`}
                  {c.reason && c.status !== "VOIDED" && ` · ${c.reason}`}
                </span>
              </dt>
              <dd className="flex items-center gap-1">
                {formatNaira(c.total, { kobo: true })}
                {inHouse && c.status === "ACTIVE" && c.category !== "BAR" && can("bills.update") && (
                  <button type="button" aria-label={`Void ${c.description}`} className="rounded p-0.5 text-muted hover:bg-surface-muted hover:text-danger" onClick={() => setVoiding(c)}>
                    <X className="size-3.5" aria-hidden="true" />
                  </button>
                )}
              </dd>
            </div>
          ))}
          <div className="flex justify-between border-t border-border pt-2 font-bold"><dt>Total</dt><dd>{formatNaira(r.grand_total, { kobo: true })}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Paid</dt><dd>{formatNaira(r.amount_paid, { kobo: true })}</dd></div>
          <div className="flex justify-between text-base font-bold"><dt>Balance</dt><dd>{formatNaira(r.balance, { kobo: true })}</dd></div>
          {r.balance_at_checkout && <p className="text-xs text-warning">Checked out owing {formatNaira(r.balance_at_checkout, { kobo: true })} (manager override).</p>}
        </dl>
      </CardBody>
      {adding && <AddChargeDialog r={r} onClose={() => setAdding(false)} />}
      {voiding && <VoidChargeDialog r={r} charge={voiding} onClose={() => setVoiding(null)} />}
    </Card>
  );
}

function AddChargeDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { can } = useSession();
  const services = useQuery({ queryKey: [...stayKeys.services, "active"], queryFn: () => stayApi.services(true) });
  const [mode, setMode] = useState<"service" | "other" | "adjustment">("service");
  const [f, setF] = useState({ service_id: "", quantity: "1", description: "", unit_price: "", amount: "", reason: "", vat: true, sc: true, stay_id: "" });
  const openStays = (r.stays ?? []).filter((s) => s.status === "OPEN");

  const add = useMutation({
    mutationFn: () => {
      const stay_id = f.stay_id || null;
      const body: AddChargeBody =
        mode === "service"
          ? { service_id: Number(f.service_id), quantity: f.quantity, stay_id }
          : mode === "other"
            ? { category: "OTHER", description: f.description, unit_price: f.unit_price, quantity: f.quantity, charges_vat: f.vat, charges_service_charge: f.sc, stay_id, reason: f.reason || undefined }
            : { category: "ADJUSTMENT", description: f.description || "Adjustment", amount: f.amount, reason: f.reason, stay_id };
      return stayApi.addCharge(r.id, body);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: hotelKeys.reservation(r.id) });
      onClose();
    },
  });
  const err = isApiError(add.error) ? add.error : null;
  const num = (v: string) => v.replace(/[^\d.]/g, "");
  const chosen = services.data?.items.find((s) => String(s.id) === f.service_id);
  const valid =
    mode === "service" ? !!f.service_id && Number(f.quantity) > 0
      : mode === "other" ? f.description.trim() !== "" && Number(f.unit_price) >= 0 && f.unit_price !== "" && Number(f.quantity) > 0
        : Number(f.amount) > 0 && f.reason.trim().length > 2;

  return (
    <Dialog
      open
      onClose={onClose}
      title="Add to the bill"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={add.isPending} disabled={!valid} onClick={() => add.mutate()}>Add</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div role="tablist" className="inline-flex rounded-lg bg-surface-muted p-1 text-sm">
          {(["service", "other", ...(can("discounts.approve") ? ["adjustment"] : [])] as const).map((m) => (
            <button key={m} type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m as typeof mode)}
              className={`rounded-md px-3 py-1.5 font-medium ${mode === m ? "bg-surface shadow-sm" : "text-muted"}`}>
              {m === "service" ? "Service" : m === "other" ? "Other item" : "Reduction"}
            </button>
          ))}
        </div>
        {add.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(add.error)}</Alert>}

        {mode === "service" && (
          <div className="grid gap-3 sm:grid-cols-[1fr_120px]">
            <Field label="Service" required error={err?.field("service_id")}>
              <Select value={f.service_id} onChange={(e) => setF((x) => ({ ...x, service_id: e.target.value }))}>
                <option value="">{services.isPending ? "Loading…" : "Choose a service"}</option>
                {services.data?.items.map((s) => (<option key={s.id} value={s.id}>{s.name} · {formatNaira(s.price)}</option>))}
              </Select>
            </Field>
            <Field label="Quantity" error={err?.field("quantity")}>
              <Input inputMode="decimal" value={f.quantity} onChange={(e) => setF((x) => ({ ...x, quantity: num(e.target.value) }))} />
            </Field>
            {chosen && (
              <p className="text-xs text-muted sm:col-span-2">
                {formatNaira(chosen.price)} each{chosen.charges_service_charge ? " + service charge" : ""}{chosen.charges_vat ? " + VAT" : ""}.
              </p>
            )}
            {services.data && services.data.items.length === 0 && <p className="text-xs text-muted sm:col-span-2">No services set up yet - use “Other item” or add services in Settings → Services.</p>}
          </div>
        )}

        {mode === "other" && (
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Description" required className="sm:col-span-3" error={err?.field("description")}>
              <Input value={f.description} maxLength={255} onChange={(e) => setF((x) => ({ ...x, description: e.target.value }))} />
            </Field>
            <Field label="Price (₦)" required error={err?.field("unit_price")}>
              <Input inputMode="decimal" value={f.unit_price} onChange={(e) => setF((x) => ({ ...x, unit_price: num(e.target.value) }))} />
            </Field>
            <Field label="Quantity" error={err?.field("quantity")}>
              <Input inputMode="decimal" value={f.quantity} onChange={(e) => setF((x) => ({ ...x, quantity: num(e.target.value) }))} />
            </Field>
            <div className="space-y-1 pt-5">
              <Checkbox label="Add service charge" checked={f.sc} onChange={(e) => setF((x) => ({ ...x, sc: e.target.checked }))} />
              <Checkbox label="Add VAT" checked={f.vat} onChange={(e) => setF((x) => ({ ...x, vat: e.target.checked }))} />
            </div>
          </div>
        )}

        {mode === "adjustment" && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Amount to take off (₦, incl. tax)" required error={err?.field("amount")}>
              <Input inputMode="decimal" value={f.amount} onChange={(e) => setF((x) => ({ ...x, amount: num(e.target.value) }))} />
            </Field>
            <Field label="Description">
              <Input value={f.description} maxLength={255} onChange={(e) => setF((x) => ({ ...x, description: e.target.value }))} placeholder="e.g. Goodwill discount" />
            </Field>
            <Field label="Reason" required className="sm:col-span-2" error={err?.field("reason")}>
              <Input value={f.reason} maxLength={500} onChange={(e) => setF((x) => ({ ...x, reason: e.target.value }))} />
            </Field>
          </div>
        )}

        {openStays.length > 1 && mode !== "adjustment" && (
          <Field label="Room">
            <Select value={f.stay_id} onChange={(e) => setF((x) => ({ ...x, stay_id: e.target.value }))}>
              <option value="">Whole booking</option>
              {openStays.map((s) => (<option key={s.id} value={s.id}>Room {s.room?.number}</option>))}
            </Select>
          </Field>
        )}
      </div>
    </Dialog>
  );
}

function VoidChargeDialog({ r, charge, onClose }: { r: Reservation; charge: Charge; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState("");
  const voidIt = useMutation({
    mutationFn: () => stayApi.voidCharge(charge.id, reason.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: hotelKeys.reservation(r.id) });
      onClose();
    },
  });

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Void “${charge.description}”?`}
      description={`${formatNaira(charge.total, { kobo: true })} comes off the bill. The line stays visible as voided.`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Back</Button>
          <Button variant="danger" loading={voidIt.isPending} disabled={reason.trim().length < 3} onClick={() => voidIt.mutate()}>Void charge</Button>
        </>
      }
    >
      {voidIt.isError && <Alert tone="danger" className="mb-3">{errorMessage(voidIt.error)}</Alert>}
      <Field label="Reason" required>
        <Input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
      </Field>
    </Dialog>
  );
}
