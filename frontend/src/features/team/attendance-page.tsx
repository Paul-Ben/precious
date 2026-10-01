"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Download } from "lucide-react";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { addDays, formatStayDate, todayInHotel } from "@/lib/dates";
import { cn } from "@/lib/utils";
import { type Shift, attendanceBadge, hotelTime, hoursLabel, teamApi, teamKeys, weekStart } from "./api";
import { AttendanceDialog } from "./attendance-dialog";
import { useNow } from "./use-now";

type Tab = "people" | "attention";

export function AttendancePage() {
  return (
    <RequirePermission permission={["staff.view", "staff.schedule"]}>
      <Inner />
    </RequirePermission>
  );
}

function presets(today: string) {
  const monday = weekStart(today);
  return [
    { key: "week", label: "This week", from: monday, to: today },
    { key: "last-week", label: "Last week", from: addDays(monday, -7), to: addDays(monday, -1) },
    { key: "month", label: "This month", from: `${today.slice(0, 8)}01`, to: today },
  ];
}

function Inner() {
  const { can } = useSession();
  const today = todayInHotel();
  const options = presets(today);
  const [preset, setPreset] = useState("week");
  const [range, setRange] = useState({ from: options[0]!.from, to: options[0]!.to });
  const [tab, setTab] = useState<Tab>("attention");
  const [fixing, setFixing] = useState<Shift | null>(null);
  const now = useNow();

  const summary = useQuery({ queryKey: teamKeys.summary(range.from, range.to), queryFn: () => teamApi.summary(range.from, range.to), placeholderData: keepPreviousData });
  const attention = useQuery({
    queryKey: teamKeys.attendance(range.from, range.to, true),
    queryFn: () => teamApi.attendance(range.from, range.to, true),
    placeholderData: keepPreviousData,
    enabled: tab === "attention",
  });

  const setDate = (field: "from" | "to") => (value: string) => {
    if (!value) return;
    setPreset("custom");
    setRange((r) => {
      const next = { ...r, [field]: value };
      return next.from > next.to ? { from: value, to: value } : next;
    });
  };

  const totals = summary.data?.totals;

  return (
    <>
      <PageHeader title="Attendance" description="Clock-ins, lateness and absences for shifts that have started. Hours only — no pay." />

      <div className="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        <div role="group" aria-label="Date range" className="inline-flex rounded-lg bg-surface-muted p-1">
          {options.map((p) => (
            <button
              key={p.key}
              type="button"
              aria-pressed={preset === p.key}
              onClick={() => {
                setPreset(p.key);
                setRange({ from: p.from, to: p.to });
              }}
              className={cn("rounded-md px-3 py-1.5 text-sm font-medium", preset === p.key ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
            >
              {p.label}
            </button>
          ))}
        </div>
        <Field label="From">
          <Input type="date" value={range.from} max={today} onChange={(e) => setDate("from")(e.target.value)} />
        </Field>
        <Field label="To">
          <Input type="date" value={range.to} onChange={(e) => setDate("to")(e.target.value)} />
        </Field>
        <a
          href={teamApi.exportUrl(range.from, range.to)}
          download
          className="inline-flex h-11 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium hover:bg-surface-muted"
        >
          <Download className="size-4" aria-hidden /> CSV
        </a>
      </div>

      {totals && (
        <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[
            ["Shifts", String(totals.shifts)],
            ["Hours worked", hoursLabel(totals.worked_minutes)],
            ["Late", String(totals.late)],
            ["Absent", String(totals.absent)],
          ].map(([label, value]) => (
            <Card key={label}>
              <CardBody>
                <p className="text-xs uppercase tracking-wider text-muted">{label}</p>
                <p className="mt-1 text-2xl font-bold tabular-nums">{value}</p>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <div role="tablist" aria-label="Attendance views" className="mb-4 inline-flex rounded-lg bg-surface-muted p-1">
        {(
          [
            ["attention", "Needs attention"],
            ["people", "By person"],
          ] as [Tab, string][]
        ).map(([t, label]) => (
          <button
            key={t}
            type="button"
            role="tab"
            aria-selected={tab === t}
            onClick={() => setTab(t)}
            className={cn("rounded-md px-4 py-2 text-sm font-medium", tab === t ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
          >
            {label}
          </button>
        ))}
      </div>

      <Card>
        {tab === "people" ? (
          summary.isPending ? (
            <LoadingState />
          ) : summary.isError ? (
            <ErrorState error={summary.error} onRetry={() => summary.refetch()} />
          ) : summary.data.people.length === 0 ? (
            <EmptyState title="No shifts in this range" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-2 font-medium">Person</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Shifts</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Scheduled</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Worked</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Late</th>
                    <th scope="col" className="px-5 py-2 text-right font-medium">Absent</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {summary.data.people.map((p) => (
                    <tr key={p.user_id}>
                      <td className="px-5 py-2">
                        <span className="font-medium">{p.name}</span>
                        <span className="block text-xs text-muted">{[p.employee_number, p.department].filter(Boolean).join(" · ")}</span>
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{p.shifts}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{hoursLabel(p.scheduled_minutes)}</td>
                      <td className="px-3 py-2 text-right font-medium tabular-nums">{hoursLabel(p.worked_minutes)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">
                        {p.late}
                        {p.late > 0 && <span className="block text-xs text-muted">{p.late_minutes} min</span>}
                      </td>
                      <td className={cn("px-5 py-2 text-right tabular-nums", p.absent > 0 && "font-medium text-danger")}>{p.absent}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : attention.isPending ? (
          <LoadingState />
        ) : attention.isError ? (
          <ErrorState error={attention.error} onRetry={() => attention.refetch()} />
        ) : attention.data.length === 0 ? (
          <EmptyState title="All clear">Everyone clocked in on time and out again.</EmptyState>
        ) : (
          <ul className="divide-y divide-border">
            {attention.data.map((s) => {
              const badge = attendanceBadge(s, now);
              return (
                <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium">{s.user?.name}</p>
                    <p className="text-xs text-muted">
                      {formatStayDate(s.date)} · {s.start_time}–{s.end_time}
                      {s.location && ` · ${s.location}`}
                      {s.attendance?.clock_in_at && ` · in ${hotelTime(s.attendance.clock_in_at)}`}
                      {s.attendance?.clock_out_at && ` · out ${hotelTime(s.attendance.clock_out_at)}`}
                    </p>
                  </div>
                  <Badge tone={badge.tone}>{badge.label}</Badge>
                  {can("staff.schedule") && (
                    <Button size="sm" variant="outline" onClick={() => setFixing(s)}>
                      {s.attendance ? "Correct" : "Record"}
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      <AttendanceDialog shift={fixing} onClose={() => setFixing(null)} />
    </>
  );
}
