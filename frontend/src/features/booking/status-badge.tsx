import { Badge } from "@/components/ui/badge";
import type { PaymentStatus, ReservationStatus } from "@/lib/api/types";

const statusLabels: Record<ReservationStatus, [string, "neutral" | "success" | "warning" | "danger" | "info" | "brand"]> = {
  DRAFT: ["Draft", "neutral"],
  PENDING_PAYMENT: ["Awaiting payment", "warning"],
  CONFIRMED: ["Confirmed", "info"],
  PARTIALLY_PAID: ["Partially paid", "info"],
  CHECK_IN_PENDING: ["Check-in pending", "info"],
  CHECKED_IN: ["Checked in", "success"],
  CHECKED_OUT: ["Checked out", "neutral"],
  CANCELLED: ["Cancelled", "danger"],
  EXPIRED: ["Expired", "neutral"],
  NO_SHOW: ["No-show", "danger"],
};

const paymentLabels: Record<PaymentStatus, [string, "neutral" | "success" | "warning" | "danger" | "info"]> = {
  UNPAID: ["Unpaid", "warning"],
  DEPOSIT_PAID: ["Deposit paid", "info"],
  PARTIALLY_PAID: ["Part paid", "info"],
  PAID: ["Paid", "success"],
  REFUNDED: ["Refunded", "neutral"],
};

export function ReservationStatusBadge({ status }: { status: ReservationStatus }) {
  const [label, tone] = statusLabels[status] ?? [status, "neutral"];
  return <Badge tone={tone}>{label}</Badge>;
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const [label, tone] = paymentLabels[status] ?? [status, "neutral"];
  return <Badge tone={tone}>{label}</Badge>;
}
