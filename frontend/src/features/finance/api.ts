import { api, bffUrl } from "@/lib/api/client";
import type { PaginationMeta } from "@/lib/api/types";
import { addDays, todayInHotel } from "@/lib/dates";

export type ExpenseStatus = "PENDING" | "APPROVED" | "REJECTED" | "VOID";
export type ExpenseMethod = "CASH" | "BANK_TRANSFER" | "POS" | "CHEQUE";

export const EXPENSE_METHODS: { value: ExpenseMethod; label: string }[] = [
  { value: "CASH", label: "Cash" },
  { value: "BANK_TRANSFER", label: "Bank transfer" },
  { value: "POS", label: "POS / card" },
  { value: "CHEQUE", label: "Cheque" },
];

export const EXPENSE_STATUS: Record<ExpenseStatus, { label: string; tone: "warning" | "success" | "danger" | "neutral" }> = {
  PENDING: { label: "Waiting for approval", tone: "warning" },
  APPROVED: { label: "Approved", tone: "success" },
  REJECTED: { label: "Rejected", tone: "danger" },
  VOID: { label: "Void", tone: "neutral" },
};

export interface ExpenseCategory {
  id: number;
  name: string;
  is_active: boolean;
}

export interface Expense {
  id: string;
  number: string;
  category: { id: number; name: string };
  expense_date: string;
  description: string;
  payee: string | null;
  amount: string;
  method: ExpenseMethod;
  method_label: string;
  reference: string | null;
  status: ExpenseStatus;
  has_receipt: boolean;
  receipt_name: string | null;
  recorded_by: { id: string; name: string } | null;
  decided_by: string | null;
  decided_at: string | null;
  rejection_reason: string | null;
  voided_by: string | null;
  voided_at: string | null;
  void_reason: string | null;
  created_at: string;
}

export interface ExpenseInput {
  category_id: number;
  expense_date: string;
  description: string;
  payee?: string | null;
  amount: string;
  method: ExpenseMethod;
  reference?: string | null;
}

export interface ExpenseFilters {
  from?: string;
  to?: string;
  status?: ExpenseStatus | "";
  category_id?: number | "";
  method?: ExpenseMethod | "";
  search?: string;
  page?: number;
}

export interface ExpenseListMeta extends PaginationMeta {
  approved_total: string;
  approved_count: number;
  pending_count: number;
  approval_limit: string;
}

export interface MethodTotal {
  method: string;
  label: string;
  count: number;
  amount: string;
}

export interface FinanceSummary {
  from: string;
  to: string;
  revenue: {
    received: string;
    refunded: string;
    net: string;
    hotel: string;
    bar: string;
    by_method: MethodTotal[];
    billed: { rooms: string; services: string; bar: string };
  };
  expenses: {
    total: string;
    by_category: { category: string; count: number; amount: string }[];
    pending_count: number;
    pending_amount: string;
  };
  net_position: string;
  outstanding: { reservations: string; bar_tabs: string; total: string; count: number };
  days: {
    date: string;
    received: string;
    refunded: string;
    expenses: string;
    net: string;
    closing: { status: "CLOSED" | "REOPENED"; cash_difference: string } | null;
  }[];
}

export interface Outstanding {
  reservations: {
    id: string;
    number: string;
    guest: string | null;
    status: string;
    check_in: string;
    check_out: string;
    total: string;
    paid: string;
    balance: string;
  }[];
  bar_tabs: {
    id: string;
    number: string;
    table: string | null;
    customer: string | null;
    waiter: string | null;
    opened_at: string | null;
    total: string;
    paid: string;
    balance: string;
  }[];
  total: string;
}

export interface DayClosingInfo {
  status: "CLOSED" | "REOPENED";
  cash_expected: string;
  cash_counted: string;
  cash_difference: string;
  note: string | null;
  closed_by: string | null;
  closed_at: string | null;
  reopened_by: string | null;
  reopened_at: string | null;
  reopen_reason: string | null;
}

export interface FinanceDay {
  date: string;
  status: "OPEN" | "CLOSED" | "REOPENED";
  received: string;
  by_method: MethodTotal[];
  hotel: string;
  bar: string;
  refunds: { total: string; count: number; cash: string };
  expenses: {
    approved: string;
    cash_paid: string;
    pending_count: number;
    lines: { number: string; category: string | null; description: string; method: ExpenseMethod; status: ExpenseStatus; amount: string }[];
  };
  cash: { received: string; refunded: string; expenses: string; expected: string };
  needs_attention: number;
  closing: DayClosingInfo | null;
}

export type ExportType = "summary" | "expenses" | "refunds" | "outstanding";

export const financeKeys = {
  summary: (from: string, to: string) => ["finance", "summary", from, to] as const,
  outstanding: ["finance", "outstanding"] as const,
  day: (date: string) => ["finance", "day", date] as const,
  closings: (month: string) => ["finance", "closings", month] as const,
  expenses: (f: ExpenseFilters) => ["finance", "expenses", f] as const,
  expense: (id: string) => ["finance", "expense", id] as const,
  categories: ["finance", "categories"] as const,
};

export const financeApi = {
  summary: async (from: string, to: string) => (await api.get<FinanceSummary>("finance/summary", { from, to })).data,
  outstanding: async () => (await api.get<Outstanding>("finance/outstanding")).data,
  day: async (date: string) => (await api.get<FinanceDay>("finance/day", { date })).data,
  closings: async (month: string) =>
    (await api.get<{ month: string; closings: { date: string; status: string; cash_difference: string; closed_by: string | null }[] }>("finance/closings", { month })).data,
  close: (date: string, cash_counted: string, note?: string) => api.post<FinanceDay>("finance/day/close", { date, cash_counted, note: note || undefined }),
  reopen: (date: string, reason: string) => api.post<FinanceDay>("finance/day/reopen", { date, reason }),
  exportUrl: (type: ExportType, format: "csv" | "xlsx", from?: string, to?: string) => bffUrl("finance/export", { type, format, from, to }),

  expenses: async (f: ExpenseFilters, signal?: AbortSignal) => {
    const res = await api.list<Expense>("finance/expenses", { ...f, per_page: 25 }, signal);
    return { items: res.items, meta: res.meta as ExpenseListMeta };
  },
  expense: async (id: string, signal?: AbortSignal) => (await api.get<Expense>(`finance/expenses/${id}`, undefined, signal)).data,
  createExpense: (body: ExpenseInput, receipt?: File | null) => {
    if (!receipt) return api.post<Expense>("finance/expenses", body);
    const form = new FormData();
    for (const [k, v] of Object.entries(body)) if (v !== null && v !== undefined && v !== "") form.append(k, String(v));
    form.append("receipt", receipt);
    return api.upload<Expense>("finance/expenses", form);
  },
  updateExpense: (id: string, body: Partial<ExpenseInput>) => api.patch<Expense>(`finance/expenses/${id}`, body),
  approve: (id: string) => api.post<Expense>(`finance/expenses/${id}/approve`),
  reject: (id: string, reason: string) => api.post<Expense>(`finance/expenses/${id}/reject`, { reason }),
  void: (id: string, reason: string) => api.post<Expense>(`finance/expenses/${id}/void`, { reason }),
  attachReceipt: (id: string, file: File) => {
    const form = new FormData();
    form.append("receipt", file);
    return api.upload<Expense>(`finance/expenses/${id}/receipt`, form);
  },
  receiptUrl: (id: string) => bffUrl(`finance/expenses/${id}/receipt`),
  categories: async () => (await api.get<ExpenseCategory[]>("finance/expense-categories")).data,
  createCategory: (name: string) => api.post<ExpenseCategory>("finance/expense-categories", { name }),
  updateCategory: (id: number, body: Partial<Pick<ExpenseCategory, "name" | "is_active">>) => api.patch<ExpenseCategory>(`finance/expense-categories/${id}`, body),
};

/** "12,500.50" / "₦12500" / " 12500 " → "12500.50"; null when not a valid amount. */
export function parseAmount(input: string): string | null {
  const clean = input.replace(/[₦,\s]/g, "");
  if (!/^\d{1,11}(\.\d{1,2})?$/.test(clean)) return null;
  const [whole, frac = ""] = clean.split(".");
  return `${String(Number(whole))}.${frac.padEnd(2, "0")}`;
}

/** Decimal money string → kobo (exact, no floating point). */
export function toKobo(amount: string): number {
  const m = /^(-)?(\d+)(?:\.(\d{1,2}))?$/.exec(amount.trim());
  if (!m) return 0;
  const kobo = Number(m[2]) * 100 + Number((m[3] ?? "0").padEnd(2, "0"));
  return m[1] ? -kobo : kobo;
}

export function fromKobo(kobo: number): string {
  const sign = kobo < 0 ? "-" : "";
  const abs = Math.abs(kobo);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, "0")}`;
}

export type RangeKey = "today" | "week" | "month" | "last-month" | "custom";

export const RANGES: { key: Exclude<RangeKey, "custom">; label: string }[] = [
  { key: "today", label: "Today" },
  { key: "week", label: "Last 7 days" },
  { key: "month", label: "This month" },
  { key: "last-month", label: "Last month" },
];

export function rangeFor(key: Exclude<RangeKey, "custom">, today = todayInHotel()): { from: string; to: string } {
  const monthStart = `${today.slice(0, 8)}01`;
  switch (key) {
    case "today":
      return { from: today, to: today };
    case "week":
      return { from: addDays(today, -6), to: today };
    case "month":
      return { from: monthStart, to: today };
    case "last-month": {
      const last = addDays(monthStart, -1);
      return { from: `${last.slice(0, 8)}01`, to: last };
    }
  }
}
