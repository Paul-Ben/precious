import { api, bffUrl } from "@/lib/api/client";
import { addDays, todayInHotel } from "@/lib/dates";

export interface ReportRange {
  from: string;
  to: string;
}

export interface DailyRow {
  date: string;
  room_nights: number;
  occupancy_percent: number;
  room_revenue: string;
  bar_sales: string;
  received: string;
}

export interface ReportSummary {
  from: string;
  to: string;
  days: number;
  hotel: {
    rooms: number;
    room_nights: number;
    occupancy_percent: number;
    room_revenue: string;
    adr: string;
    revpar: string;
    extras: { category: string; lines: number; subtotal: string; total: string }[];
  };
  bar: {
    bills: number;
    sales: string;
    discounts: string;
    service_charge: string;
    vat: string;
    total: string;
    charged_to_room: string;
    average_bill: string;
    top_products: { name: string; quantity: number; revenue: string }[];
    by_waiter: { name: string; bills: number; total: string }[];
  };
  payments: {
    received: string;
    refunded: string;
    net: string;
    by_method: { method: string; label: string; amount: string }[];
    hotel: string;
    bar: string;
  };
  daily: DailyRow[];
}

export type ExportType = "payments" | "bar-sales" | "reservations";

export const reportKeys = {
  summary: (r: ReportRange) => ["reports", "summary", r] as const,
};

export const reportsApi = {
  summary: async (r: ReportRange) => (await api.get<ReportSummary>("reports/summary", { from: r.from, to: r.to })).data,
  exportUrl: (type: ExportType, r: ReportRange) => bffUrl("reports/export", { type, from: r.from, to: r.to }),
};

export type PresetKey = "today" | "7d" | "30d" | "month" | "last-month";

export const PRESETS: { key: PresetKey; label: string }[] = [
  { key: "today", label: "Today" },
  { key: "7d", label: "Last 7 days" },
  { key: "30d", label: "Last 30 days" },
  { key: "month", label: "This month" },
  { key: "last-month", label: "Last month" },
];

/** Resolves a preset to hotel-calendar dates (inclusive). */
export function presetRange(key: PresetKey, today = todayInHotel()): ReportRange {
  const monthStart = `${today.slice(0, 8)}01`;
  switch (key) {
    case "today":
      return { from: today, to: today };
    case "7d":
      return { from: addDays(today, -6), to: today };
    case "30d":
      return { from: addDays(today, -29), to: today };
    case "month":
      return { from: monthStart, to: today };
    case "last-month": {
      const lastDay = addDays(monthStart, -1);
      return { from: `${lastDay.slice(0, 8)}01`, to: lastDay };
    }
  }
}

/** Decimal money string → number of naira (for chart scaling only, never for sums shown to users). */
export function toNumber(amount: string): number {
  const n = Number(amount);
  return Number.isFinite(n) ? n : 0;
}
