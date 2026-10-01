"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Download } from "lucide-react";
import { useState } from "react";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import {
  type DailyRow,
  type ExportType,
  PRESETS,
  type PresetKey,
  type ReportRange,
  type ReportSummary,
  presetRange,
  reportKeys,
  reportsApi,
  toNumber,
} from "./api";

const EXPORTS: { type: ExportType; label: string }[] = [
  { type: "payments", label: "Payments" },
  { type: "bar-sales", label: "Bar bills" },
  { type: "reservations", label: "Reservations" },
];

export function ReportsPage() {
  return (
    <RequirePermission permission="reports.view">
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const { can } = useSession();
  const [preset, setPreset] = useState<PresetKey | "custom">("7d");
  const [range, setRange] = useState<ReportRange>(() => presetRange("7d"));
  const q = useQuery({ queryKey: reportKeys.summary(range), queryFn: () => reportsApi.summary(range), placeholderData: keepPreviousData });

  const pick = (key: PresetKey) => {
    setPreset(key);
    setRange(presetRange(key));
  };
  const setDate = (field: keyof ReportRange) => (value: string) => {
    if (!value) return;
    setPreset("custom");
    setRange((r) => {
      const next = { ...r, [field]: value };
      // Keep the range valid: if the ends cross, collapse to the day just picked.
      return next.from > next.to ? { from: value, to: value } : next;
    });
  };

  return (
    <>
      <PageHeader title="Reports" description="Occupancy, room and bar sales, and money received. Days follow the hotel's calendar." />

      <div className="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        <div role="group" aria-label="Date range" className="inline-flex flex-wrap rounded-lg bg-surface-muted p-1">
          {PRESETS.map((p) => (
            <button
              key={p.key}
              type="button"
              aria-pressed={preset === p.key}
              onClick={() => pick(p.key)}
              className={cn("rounded-md px-3 py-1.5 text-sm font-medium", preset === p.key ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
            >
              {p.label}
            </button>
          ))}
        </div>
        <Field label="From">
          <Input type="date" value={range.from} max={todayInHotel()} onChange={(e) => setDate("from")(e.target.value)} />
        </Field>
        <Field label="To">
          <Input type="date" value={range.to} onChange={(e) => setDate("to")(e.target.value)} />
        </Field>
        {can("reports.export") && (
          <div className="flex flex-wrap items-center gap-2">
            {EXPORTS.map((e) => (
              <a
                key={e.type}
                href={reportsApi.exportUrl(e.type, range)}
                download
                className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-surface-muted"
              >
                <Download className="size-4" aria-hidden />
                {e.label} CSV
              </a>
            ))}
          </div>
        )}
      </div>

      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <div className={cn("space-y-4 transition-opacity", q.isPlaceholderData && "opacity-60")}>
          <Report data={q.data} />
        </div>
      )}
    </>
  );
}

function Report({ data }: { data: ReportSummary }) {
  const { hotel, bar, payments } = data;

  return (
    <>
      <p className="text-sm text-muted">
        {formatStayDate(data.from, true)} – {formatStayDate(data.to, true)} · {data.days} day{data.days === 1 ? "" : "s"}
      </p>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Occupancy" value={`${hotel.occupancy_percent}%`} hint={`${hotel.room_nights} of ${hotel.rooms * data.days} room nights`} />
        <Kpi label="Room revenue" value={formatNaira(hotel.room_revenue)} hint={`ADR ${formatNaira(hotel.adr)} · RevPAR ${formatNaira(hotel.revpar)}`} />
        <Kpi label="Bar sales" value={formatNaira(bar.sales)} hint={`${bar.bills} bill${bar.bills === 1 ? "" : "s"} · avg ${formatNaira(bar.average_bill)}`} />
        <Kpi label="Money received" value={formatNaira(payments.received)} hint={`Net of refunds ${formatNaira(payments.net)}`} />
      </div>

      <Card>
        <CardHeader title="Revenue by day" description="Room revenue (before tax) and bar sales (after discounts, before service charge and VAT)." />
        <CardBody>
          <DailyChart rows={data.daily} />
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Bar" description="Closed bills in the range." />
          <Rows
            rows={[
              ["Sales (after discounts)", formatNaira(bar.sales)],
              ["Discounts given", formatNaira(bar.discounts)],
              ["Service charge", formatNaira(bar.service_charge)],
              ["VAT", formatNaira(bar.vat)],
              ["Bills total", formatNaira(bar.total)],
              ["Of which charged to rooms", formatNaira(bar.charged_to_room)],
            ]}
          />
        </Card>
        <Card>
          <CardHeader title="Money received" description="Successful payments by method. Gateway fees paid by guests are excluded." />
          <Rows
            rows={[
              ...payments.by_method.filter((m) => toNumber(m.amount) > 0).map((m) => [m.label, formatNaira(m.amount)] as [string, string]),
              ["Hotel", formatNaira(payments.hotel)],
              ["Bar", formatNaira(payments.bar)],
              ["Refunded", formatNaira(payments.refunded)],
              ["Net", formatNaira(payments.net)],
            ]}
          />
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Top bar products" />
          <Table
            empty="No bar sales in this range."
            head={["Product", "Qty", "Revenue"]}
            rows={bar.top_products.map((p) => [p.name, String(p.quantity), formatNaira(p.revenue)])}
          />
        </Card>
        <Card>
          <CardHeader title="Bills by waiter" />
          <Table
            empty="No closed bills in this range."
            head={["Waiter", "Bills", "Total"]}
            rows={bar.by_waiter.map((w) => [w.name, String(w.bills), formatNaira(w.total)])}
          />
        </Card>
      </div>

      {hotel.extras.length > 0 && (
        <Card>
          <CardHeader title="Guest extras" description="Charges added to room bills (bar charges are counted under Bar)." />
          <Table
            empty=""
            head={["Category", "Lines", "Total"]}
            rows={hotel.extras.map((e) => [e.category.charAt(0) + e.category.slice(1).toLowerCase().replace(/_/g, " "), String(e.lines), formatNaira(e.total)])}
          />
        </Card>
      )}
    </>
  );
}

function Kpi({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <Card>
      <CardBody>
        <p className="text-xs uppercase tracking-wider text-muted">{label}</p>
        <p className="mt-1 text-2xl font-bold">{value}</p>
        <p className="mt-1 text-xs text-muted">{hint}</p>
      </CardBody>
    </Card>
  );
}

function Rows({ rows }: { rows: [string, string][] }) {
  return (
    <dl className="divide-y divide-border text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-4 px-5 py-2">
          <dt className="text-muted">{k}</dt>
          <dd className="font-medium tabular-nums">{v}</dd>
        </div>
      ))}
    </dl>
  );
}

function Table({ head, rows, empty }: { head: string[]; rows: string[][]; empty: string }) {
  if (rows.length === 0) return <CardBody className="text-sm text-muted">{empty}</CardBody>;
  return (
    <table className="w-full text-left text-sm">
      <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
        <tr>
          {head.map((h, i) => (
            <th key={h} className={cn("py-2 font-medium", i === 0 ? "px-5" : "px-3 text-right", i === head.length - 1 && "pr-5")}>{h}</th>
          ))}
        </tr>
      </thead>
      <tbody className="divide-y divide-border">
        {rows.map((r, ri) => (
          <tr key={ri}>
            {r.map((c, i) => (
              <td key={i} className={cn("py-2", i === 0 ? "px-5" : "px-3 text-right tabular-nums", i === r.length - 1 && "pr-5 font-medium")}>{c}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** Stacked daily bars: rooms (brand) + bar (accent). Plain SVG; the table view below is the accessible version. */
function DailyChart({ rows }: { rows: DailyRow[] }) {
  const [showTable, setShowTable] = useState(false);
  const values = rows.map((r) => ({ ...r, rooms: toNumber(r.room_revenue), barN: toNumber(r.bar_sales) }));
  const max = Math.max(1, ...values.map((v) => v.rooms + v.barN));
  const width = 720;
  const height = 200;
  const pad = { top: 8, bottom: 24, left: 4, right: 4 };
  const slot = (width - pad.left - pad.right) / Math.max(1, values.length);
  const bar = Math.max(2, Math.min(40, slot * 0.7));
  const y = (v: number) => ((height - pad.top - pad.bottom) * v) / max;
  const labelEvery = Math.ceil(values.length / 10);

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-4 text-xs text-muted">
        <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-brand" aria-hidden /> Rooms</span>
        <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-accent" aria-hidden /> Bar</span>
        <span>Peak day {formatNaira(String(max.toFixed(2)))}</span>
        <button type="button" className="ml-auto underline hover:text-foreground" onClick={() => setShowTable((s) => !s)}>
          {showTable ? "Show chart" : "Show as table"}
        </button>
      </div>

      {showTable ? (
        <Table
          empty="No days."
          head={["Date", "Occupancy", "Rooms", "Bar", "Received"]}
          rows={rows.map((r) => [formatStayDate(r.date), `${r.occupancy_percent}%`, formatNaira(r.room_revenue), formatNaira(r.bar_sales), formatNaira(r.received)])}
        />
      ) : (
        <svg viewBox={`0 0 ${width} ${height}`} className="h-52 w-full" role="img" aria-label="Revenue by day, rooms and bar">
          <line x1={pad.left} x2={width - pad.right} y1={height - pad.bottom} y2={height - pad.bottom} className="stroke-border" />
          {values.map((v, i) => {
            const x = pad.left + i * slot + (slot - bar) / 2;
            const base = height - pad.bottom;
            const hr = y(v.rooms);
            const hb = y(v.barN);
            return (
              <g key={v.date}>
                <title>{`${formatStayDate(v.date)}: rooms ${formatNaira(v.room_revenue)}, bar ${formatNaira(v.bar_sales)}, occupancy ${v.occupancy_percent}%`}</title>
                <rect x={x} y={base - hr} width={bar} height={hr} className="fill-brand" rx={1} />
                <rect x={x} y={base - hr - hb} width={bar} height={hb} className="fill-accent" rx={1} />
                {i % labelEvery === 0 && (
                  <text x={x + bar / 2} y={height - 6} textAnchor="middle" className="fill-muted text-[10px]">
                    {v.date.slice(8)}
                  </text>
                )}
              </g>
            );
          })}
        </svg>
      )}
    </div>
  );
}
