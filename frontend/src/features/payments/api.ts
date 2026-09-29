import { api } from "@/lib/api/client";
import type {
  CashUp,
  Checkout,
  GatewayFees,
  Payment,
  PaymentOptions,
  PaymentVerification,
  Receipt,
  Refund,
  RefundMethod,
} from "@/lib/api/types";

type Q = Record<string, string | number | boolean | null | undefined>;

/** Who is paying: a guest with their booking link, or a signed-in customer. */
export type Payer = { kind: "guest"; number: string; token: string } | { kind: "customer"; id: string };

export const paymentKeys = {
  options: (payer: Payer) => ["payments", "options", payer.kind === "guest" ? payer.number : payer.id] as const,
  list: (f: Q = {}) => ["payments", "list", f] as const,
  payment: (id: string) => ["payments", "payment", id] as const,
  reservation: (id: string) => ["payments", "reservation", id] as const,
  receipt: (number: string) => ["payments", "receipt", number] as const,
  refunds: (f: Q = {}) => ["payments", "refunds", f] as const,
  cashUp: (date: string) => ["payments", "cash-up", date] as const,
};

export const paymentsApi = {
  // Guest / customer
  options: async (payer: Payer) =>
    (payer.kind === "guest"
      ? await api.get<PaymentOptions>(`public/reservations/${encodeURIComponent(payer.number)}/payment-options`, { token: payer.token })
      : await api.get<PaymentOptions>(`me/reservations/${payer.id}/payment-options`)
    ).data,
  start: async (payer: Payer, option: string, gateway: string | null) =>
    (payer.kind === "guest"
      ? await api.post<Checkout>(`public/reservations/${encodeURIComponent(payer.number)}/payments`, { token: payer.token, option, gateway })
      : await api.post<Checkout>(`me/reservations/${payer.id}/payments`, { option, gateway })
    ).data,
  verify: async (reference: string) => (await api.post<PaymentVerification>("public/payments/verify", { reference })).data,

  // Staff
  list: (f: Q) => api.list<Payment>("payments", f),
  payment: async (id: string) => (await api.get<Payment>(`payments/${id}`)).data,
  forReservation: async (id: string) => (await api.get<Payment[]>(`reservations/${id}/payments`)).data,
  record: (reservationId: string, body: { method: string; amount: string; external_reference?: string; note?: string; send_receipt: boolean }) =>
    api.post<Payment>(`reservations/${reservationId}/payments`, body),
  recheck: (id: string) => api.post<Payment>(`payments/${id}/verify`),
  resolve: (id: string, note: string) => api.post<Payment>(`payments/${id}/resolve`, { note }),
  receipt: async (number: string) => (await api.get<Receipt>(`receipts/${number}`)).data,
  emailReceipt: (number: string, email?: string) => api.post<Receipt>(`receipts/${number}/email`, email ? { email } : {}),
  cashUp: async (date: string) => (await api.get<CashUp>("payments/summary", { date })).data,

  refunds: (f: Q) => api.list<Refund>("refunds", f),
  requestRefund: (paymentId: string, amount: string, reason: string) => api.post<Refund>(`payments/${paymentId}/refunds`, { amount, reason }),
  approveRefund: (id: string, note?: string) => api.post<Refund>(`refunds/${id}/approve`, note ? { note } : {}),
  rejectRefund: (id: string, note: string) => api.post<Refund>(`refunds/${id}/reject`, { note }),
  completeRefund: (id: string, method: RefundMethod, external_reference?: string) =>
    api.post<Refund>(`refunds/${id}/complete`, { method, external_reference: external_reference || null }),

  updateFees: (gateway: string, fees: GatewayFees | null) => api.patch(`settings/payment-gateways/${gateway}`, { fees }),
};

export const GATEWAY_NAMES: Record<string, string> = { paystack: "Paystack", flutterwave: "Flutterwave" };

export const ATTENTION_LABELS: Record<string, string> = {
  ROOM_NO_LONGER_AVAILABLE: "Paid after the hold expired and the room was re-sold - refund or rebook",
  RESERVATION_CANCELLED: "Paid for a cancelled reservation - refund",
  RESERVATION_NO_SHOW: "Paid for a no-show reservation - review",
  OVERPAYMENT: "More than the reservation total was paid - refund the difference",
  AMOUNT_MISMATCH: "Gateway reported a different amount - not credited, check the gateway dashboard",
};

/**
 * Where the payer lands after the gateway. Paystack adds ?reference / ?trxref,
 * Flutterwave adds ?tx_ref (and status, transaction_id).
 */
export function referenceFromQuery(params: URLSearchParams): string | null {
  return params.get("reference") ?? params.get("trxref") ?? params.get("tx_ref");
}
