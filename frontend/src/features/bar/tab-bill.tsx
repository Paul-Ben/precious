"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Mail, Printer } from "lucide-react";
import Link from "next/link";
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
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { BarOrder, BarTab } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { formatDateTime } from "@/lib/utils";
import { ORDER_STATUS_LABEL, ORDER_STATUS_TONE, barApi, barKeys } from "./api";

type Action = "pay" | "room" | "discount" | "email" | null;

/** The running bill: rounds with their bar status, totals and settlement actions. */
export function TabBill({ tab: t, onClosed }: { tab: BarTab; onClosed?: () => void }) {
  const { can, user } = useSession();
  const queryClient = useQueryClient();
  const [action, setAction] = useState<Action>(null);
  const [cancelling, setCancelling] = useState<BarOrder | null>(null);
  const open = t.status === "OPEN";
  const orders = t.orders ?? [];
  const pending = orders.filter((o) => !["DELIVERED", "CANCELLED"].includes(o.status)).length;
  const refresh = () => queryClient.invalidateQueries({ queryKey: barKeys.tab(t.id) });

  const deliver = useMutation({ mutationFn: (id: string) => barApi.advance(id, "DELIVERED"), onSuccess: refresh });
  const close = useMutation({
    mutationFn: () => barApi.close(t.id),
    onSuccess: async () => {
      await refresh();
      await queryClient.invalidateQueries({ queryKey: ["bar"] });
      onClosed?.();
    },
  });

  return (
    <Card className="h-fit">
      <CardHeader
        title="Bill"
        actions={<Link href={`/staff/bar/tabs/${t.id}/print`} target="_blank" className="inline-flex items-center gap-1 text-sm font-medium hover:underline"><Printer className="size-4" aria-hidden="true" /> Print</Link>}
      />
      <CardBody className="space-y-4 text-sm">
        {orders.length === 0 && <p className="text-muted">No orders yet.</p>}
        {orders.map((o) => (
          <div key={o.id} className={o.status === "CANCELLED" ? "opacity-60" : undefined}>
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-xs text-muted">{o.number}</span>
              <Badge tone={ORDER_STATUS_TONE[o.status]}>{ORDER_STATUS_LABEL[o.status]}</Badge>
              <span className="flex-1 text-xs text-muted">{formatDateTime(o.placed_at)}</span>
              {o.status === "READY" && can("bar.orders.deliver") && (
                <Button size="sm" loading={deliver.isPending && deliver.variables === o.id} onClick={() => deliver.mutate(o.id)}>Delivered</Button>
              )}
              {open && ["PLACED", "ACCEPTED", "PREPARING", "READY"].includes(o.status) && (can("bar.orders.cancel") || (o.status === "PLACED" && o.waiter?.id === user.id)) && (
                <Button size="sm" variant="ghost" onClick={() => setCancelling(o)}>Cancel</Button>
              )}
            </div>
            <ul className={`mt-1 space-y-0.5 ${o.status === "CANCELLED" ? "line-through" : ""}`}>
              {o.items?.map((i) => (
                <li key={i.id} className="flex justify-between gap-2">
                  <span>{i.quantity} × {i.name}{i.notes ? <span className="text-xs text-muted"> ({i.notes})</span> : null}</span>
                  <span>{formatNaira(i.line_total)}</span>
                </li>
              ))}
            </ul>
            {o.cancel_reason && <p className="text-xs text-danger">Cancelled: {o.cancel_reason}</p>}
          </div>
        ))}

        <dl className="space-y-1 border-t border-border pt-3">
          <div className="flex justify-between"><dt className="text-muted">Items</dt><dd>{formatNaira(t.subtotal, { kobo: true })}</dd></div>
          {Number(t.discount) > 0 && <div className="flex justify-between text-success"><dt>Discount{t.discount_reason ? ` (${t.discount_reason})` : ""}</dt><dd>−{formatNaira(t.discount, { kobo: true })}</dd></div>}
          <div className="flex justify-between"><dt className="text-muted">Service charge</dt><dd>{formatNaira(t.service_charge, { kobo: true })}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">VAT</dt><dd>{formatNaira(t.vat, { kobo: true })}</dd></div>
          <div className="flex justify-between text-base font-bold"><dt>Total</dt><dd>{formatNaira(t.total, { kobo: true })}</dd></div>
          <div className="flex justify-between"><dt className="text-muted">Paid</dt><dd>{formatNaira(t.amount_paid, { kobo: true })}</dd></div>
          <div className="flex justify-between text-base font-bold"><dt>To pay</dt><dd>{formatNaira(t.balance, { kobo: true })}</dd></div>
        </dl>

        {t.settlement === "CHARGED_TO_ROOM" && (
          <Alert tone="success">Charged to room {t.charged_to?.room}. Print the slip for the guest to sign.</Alert>
        )}

        {open && (
          <div className="grid grid-cols-2 gap-2">
            {can("payments.create") && Number(t.balance) > 0 && <Button onClick={() => setAction("pay")}>Take payment</Button>}
            {can("bar.orders.charge_to_room") && Number(t.amount_paid) === 0 && Number(t.total) > 0 && (
              <Button variant="outline" disabled={pending > 0} onClick={() => setAction("room")}>Charge to room</Button>
            )}
            {can("discounts.apply") && Number(t.subtotal) > 0 && <Button variant="outline" onClick={() => setAction("discount")}>Discount</Button>}
            <Button variant="outline" onClick={() => setAction("email")}><Mail className="size-4" aria-hidden="true" /> Email bill</Button>
            <Button
              variant={Number(t.balance) === 0 ? "primary" : "outline"}
              className="col-span-2"
              loading={close.isPending}
              disabled={pending > 0 || Number(t.balance) > 0}
              onClick={() => close.mutate()}
            >
              {Number(t.total) === 0 ? "Cancel empty bill" : "Close bill"}
            </Button>
            {pending > 0 && <p className="col-span-2 text-xs text-muted">{pending} order(s) still with the bar.</p>}
            {close.isError && <Alert tone="danger" className="col-span-2">{errorMessage(close.error)}</Alert>}
          </div>
        )}

        {t.payments && t.payments.length > 0 && (
          <div className="border-t border-border pt-3 text-xs text-muted">
            {t.payments.filter((p) => p.status === "SUCCESSFUL").map((p) => (
              <p key={p.id}>
                {p.method_label} {formatNaira(p.amount, { kobo: true })} · {formatDateTime(p.paid_at)}
                {p.receipt_number && <> · <Link href={`/staff/receipts/${p.receipt_number}`} className="underline">{p.receipt_number}</Link></>}
              </p>
            ))}
          </div>
        )}
      </CardBody>

      {action === "pay" && <PayDialog tab={t} onClose={() => setAction(null)} />}
      {action === "room" && <RoomChargeDialog tab={t} onClose={() => setAction(null)} />}
      {action === "discount" && <DiscountDialog tab={t} onClose={() => setAction(null)} />}
      {action === "email" && <EmailDialog tab={t} onClose={() => setAction(null)} />}
      {cancelling && <CancelOrderDialog tabId={t.id} order={cancelling} onClose={() => setCancelling(null)} />}
    </Card>
  );
}

function PayDialog({ tab, onClose }: { tab: BarTab; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [f, setF] = useState({ method: "CASH", amount: tab.balance, external_reference: "", send_receipt: !!tab.customer_email });
  const pay = useMutation({
    mutationFn: () => barApi.pay(tab.id, { method: f.method, amount: f.amount.trim(), external_reference: f.external_reference.trim() || undefined, send_receipt: f.send_receipt }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tab.id) });
      onClose();
    },
  });
  const err = isApiError(pay.error) ? pay.error : null;

  return (
    <Dialog open onClose={onClose} title={`Take payment · ${formatNaira(tab.balance, { kobo: true })} due`}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={pay.isPending} disabled={!Number(f.amount)} onClick={() => pay.mutate()}>Record {formatNaira(f.amount || "0", { kobo: true })}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        {pay.isError && !err?.isValidation && <Alert tone="danger" className="sm:col-span-2">{errorMessage(pay.error)}</Alert>}
        <Field label="Method">
          <Select value={f.method} onChange={(e) => setF((x) => ({ ...x, method: e.target.value }))}>
            <option value="CASH">Cash</option>
            <option value="POS">POS terminal</option>
            <option value="BANK_TRANSFER">Bank transfer</option>
          </Select>
        </Field>
        <Field label="Amount (₦)" error={err?.field("amount")}>
          <Input inputMode="decimal" value={f.amount} onChange={(e) => setF((x) => ({ ...x, amount: e.target.value.replace(/[^\d.]/g, "") }))} />
        </Field>
        <Field label={f.method === "BANK_TRANSFER" ? "Transfer reference" : "POS slip / reference"} required={f.method === "BANK_TRANSFER"} error={err?.field("external_reference")} className="sm:col-span-2">
          <Input value={f.external_reference} maxLength={100} onChange={(e) => setF((x) => ({ ...x, external_reference: e.target.value }))} />
        </Field>
        <Checkbox className="sm:col-span-2" label="Email the receipt" description={tab.customer_email ?? "No email on this bill"} disabled={!tab.customer_email}
          checked={f.send_receipt} onChange={(e) => setF((x) => ({ ...x, send_receipt: e.target.checked }))} />
      </div>
    </Dialog>
  );
}

function RoomChargeDialog({ tab, onClose }: { tab: BarTab; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [room, setRoom] = useState("");
  const [surname, setSurname] = useState("");
  const charge = useMutation({
    mutationFn: () => barApi.chargeToRoom(tab.id, room.trim(), surname.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tab.id) });
      await queryClient.invalidateQueries({ queryKey: ["bar"] });
    },
  });

  if (charge.isSuccess) {
    return (
      <Dialog open onClose={onClose} title="Charged to room" footer={<Button onClick={onClose}>Done</Button>}>
        <p className="text-sm">{formatNaira(tab.total, { kobo: true })} was added to room {room}&apos;s hotel bill.</p>
        <Link href={`/staff/bar/tabs/${tab.id}/print`} target="_blank" className="mt-3 inline-flex items-center gap-1 text-sm font-semibold underline">
          <Printer className="size-4" aria-hidden="true" /> Print the slip for the guest to sign
        </Link>
      </Dialog>
    );
  }

  return (
    <Dialog open onClose={onClose} title={`Charge ${formatNaira(tab.total, { kobo: true })} to a room`}
      description="Only for guests who are checked in. Ask for the room number and the guest's surname."
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={charge.isPending} disabled={!room.trim() || !surname.trim()} onClick={() => charge.mutate()}>Charge to room</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        {charge.isError && <Alert tone="danger" className="sm:col-span-2">{errorMessage(charge.error)}</Alert>}
        <Field label="Room number"><Input value={room} inputMode="numeric" onChange={(e) => setRoom(e.target.value)} /></Field>
        <Field label="Guest surname"><Input value={surname} autoComplete="off" onChange={(e) => setSurname(e.target.value)} /></Field>
      </div>
    </Dialog>
  );
}

function DiscountDialog({ tab, onClose }: { tab: BarTab; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [amount, setAmount] = useState(Number(tab.discount) > 0 ? tab.discount : "");
  const [reason, setReason] = useState(tab.discount_reason ?? "");
  const save = useMutation({
    mutationFn: () => barApi.discount(tab.id, amount.trim() || "0", reason.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tab.id) });
      onClose();
    },
  });

  return (
    <Dialog open onClose={onClose} title="Discount" description={`Taken off the items total (${formatNaira(tab.subtotal)}) before service charge and VAT. Recorded in the audit log.`}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button loading={save.isPending} disabled={reason.trim().length < 3} onClick={() => save.mutate()}>Apply</Button></>}>
      <div className="grid gap-3">
        {save.isError && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
        <Field label="Amount off (₦)"><Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ""))} /></Field>
        <Field label="Reason" required><Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} /></Field>
      </div>
    </Dialog>
  );
}

function EmailDialog({ tab, onClose }: { tab: BarTab; onClose: () => void }) {
  const [email, setEmail] = useState(tab.customer_email ?? "");
  const send = useMutation({ mutationFn: () => barApi.email(tab.id, email.trim() || undefined) });

  return (
    <Dialog open onClose={onClose} title="Email the bill" description="Includes a secure link to pay online."
      footer={<><Button variant="ghost" onClick={onClose}>Close</Button><Button loading={send.isPending} disabled={!email.trim()} onClick={() => send.mutate()}>Send</Button></>}>
      {send.isSuccess && <Alert tone="success" className="mb-3">Sent.</Alert>}
      {send.isError && <Alert tone="danger" className="mb-3">{errorMessage(send.error)}</Alert>}
      <Field label="Email"><Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
    </Dialog>
  );
}

function CancelOrderDialog({ tabId, order, onClose }: { tabId: string; order: BarOrder; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState("");
  const cancel = useMutation({
    mutationFn: () => barApi.cancelOrder(order.id, reason.trim() || undefined),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: barKeys.tab(tabId) });
      onClose();
    },
  });

  return (
    <Dialog open onClose={onClose} title={`Cancel order ${order.number}?`} description={order.status === "PLACED" ? "The bar hasn't started on it yet." : "The bar has already started on this order."}
      footer={<><Button variant="ghost" onClick={onClose}>Back</Button><Button variant="danger" loading={cancel.isPending} onClick={() => cancel.mutate()}>Cancel order</Button></>}>
      {cancel.isError && <Alert tone="danger" className="mb-3">{errorMessage(cancel.error)}</Alert>}
      <Field label="Reason" required={order.status !== "PLACED"}><Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} /></Field>
    </Dialog>
  );
}
