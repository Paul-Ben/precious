"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, Copy, Plus, Printer, Settings2 } from "lucide-react";
import { useMemo, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { PageHeader } from "@/components/ui/page-header";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import { addDays, formatStayDate, todayInHotel } from "@/lib/dates";
import { cn } from "@/lib/utils";
import { type Shift, attendanceBadge, hoursLabel, teamApi, teamKeys, weekDays, weekStart } from "./api";
import { AttendanceDialog } from "./attendance-dialog";
import { ShiftDialog } from "./shift-dialog";
import { TemplatesDialog } from "./templates-dialog";
import { useNow } from "./use-now";

export function RotaPage() {
  return (
    <RequirePermission permission={["staff.schedule", "staff.view"]}>
      <Rota />
    </RequirePermission>
  );
}

const TONE_RING: Record<string, string> = {
  success: "border-l-success",
  warning: "border-l-warning",
  danger: "border-l-danger",
  info: "border-l-info",
  neutral: "border-l-border",
};

function Rota() {
  const { can } = useSession();
  const canEdit = can("staff.schedule");
  const today = todayInHotel();
  const [monday, setMonday] = useState(() => weekStart(today));
  const [department, setDepartment] = useState("");
  const [editing, setEditing] = useState<{ shift?: Shift; initial?: { user_id?: string; date?: string } } | null>(null);
  const [attendanceFor, setAttendanceFor] = useState<Shift | null>(null);
  const [templatesOpen, setTemplatesOpen] = useState(false);
  const [copyOpen, setCopyOpen] = useState(false);
  const now = useNow();
  const thisWeek = weekStart(today);

  const days = weekDays(monday);
  const sunday = days[6]!;
  const shifts = useQuery({ queryKey: teamKeys.shifts(monday, sunday), queryFn: () => teamApi.shifts(monday, sunday), refetchInterval: 60_000 });
  const people = useQuery({ queryKey: teamKeys.schedulable, queryFn: teamApi.schedulable });
  const departments = useQuery({ queryKey: teamKeys.departments, queryFn: teamApi.departments });

  // Rows: everyone schedulable plus anyone who has a shift this week (e.g. since left).
  const rows = useMemo(() => {
    const list: { id: string; name: string; sub: string | null; departmentId: number | null; schedulable: boolean }[] = (people.data ?? []).map((p) => ({
      id: p.id,
      name: p.name,
      sub: [p.position, p.department?.name].filter(Boolean).join(" · ") || null,
      departmentId: p.department?.id ?? null,
      schedulable: true,
    }));
    for (const s of shifts.data ?? []) {
      if (s.user && !list.some((r) => r.id === s.user!.id)) list.push({ id: s.user.id, name: s.user.name, sub: null, departmentId: null, schedulable: false });
    }
    const byDept = (r: (typeof list)[number]) =>
      !department || r.departmentId === Number(department) || (shifts.data ?? []).some((s) => s.user?.id === r.id && s.department?.id === Number(department));
    return list.filter(byDept).sort((a, b) => a.name.localeCompare(b.name));
  }, [people.data, shifts.data, department]);

  const inView = (s: Shift) => !department || s.department?.id === Number(department) || !s.department;
  const cell = (userId: string, date: string) => (shifts.data ?? []).filter((s) => s.user?.id === userId && s.date === date && inView(s));
  // People on shift that day among the rows shown (follows the department filter).
  const perDay = (date: string) =>
    new Set((shifts.data ?? []).filter((s) => s.date === date && inView(s) && rows.some((r) => r.id === s.user?.id)).map((s) => s.user?.id)).size;
  const weekMinutes = (userId: string) => (shifts.data ?? []).filter((s) => s.user?.id === userId).reduce((t, s) => t + s.minutes, 0);

  return (
    <>
      <PageHeader
        title="Rota"
        description="Weekly shifts. People are emailed when a shift is added, changed or cancelled."
        actions={
          <>
            {canEdit && (
              <>
                <Button variant="outline" onClick={() => setTemplatesOpen(true)}>
                  <Settings2 className="size-4" aria-hidden /> Standard shifts
                </Button>
                <Button
                  variant="outline"
                  disabled={monday < thisWeek}
                  title={monday < thisWeek ? "Shifts can only be copied into this week or a later one." : undefined}
                  onClick={() => setCopyOpen(true)}
                >
                  <Copy className="size-4" aria-hidden /> Copy last week
                </Button>
              </>
            )}
            <Button variant="outline" onClick={() => window.print()}>
              <Printer className="size-4" aria-hidden /> Print
            </Button>
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        <Button variant="outline" size="sm" aria-label="Previous week" onClick={() => setMonday(addDays(monday, -7))}>
          <ChevronLeft className="size-4" aria-hidden />
        </Button>
        <Button variant="outline" size="sm" onClick={() => setMonday(thisWeek)} disabled={monday === thisWeek}>
          This week
        </Button>
        <Button variant="outline" size="sm" aria-label="Next week" onClick={() => setMonday(addDays(monday, 7))}>
          <ChevronRight className="size-4" aria-hidden />
        </Button>
        <p className="ml-1 text-sm font-medium">
          {formatStayDate(monday)} – {formatStayDate(sunday, true)}
        </p>
        <Select aria-label="Department" className="ml-auto h-9 w-auto" value={department} onChange={(e) => setDepartment(e.target.value)}>
          <option value="">All departments</option>
          {departments.data?.map((d) => (
            <option key={d.id} value={d.id}>{d.name}</option>
          ))}
        </Select>
      </div>

      <Card>
        {shifts.isPending || people.isPending ? (
          <LoadingState label="Loading rota…" />
        ) : shifts.isError ? (
          <ErrorState error={shifts.error} onRetry={() => shifts.refetch()} />
        ) : people.isError ? (
          <ErrorState error={people.error} onRetry={() => people.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState title="Nobody to show">No staff in this department yet.</EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[960px] table-fixed border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <th scope="col" className="w-48 px-4 py-2 font-medium">Staff</th>
                  {days.map((d) => (
                    <th key={d} scope="col" className={cn("px-2 py-2 font-medium", d === today && "text-foreground")}>
                      {formatStayDate(d)}
                      <span className="block font-normal normal-case tracking-normal">{perDay(d)} on</span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((r) => (
                  <tr key={r.id} className="align-top">
                    <th scope="row" className="px-4 py-2 font-normal">
                      <span className="font-medium">{r.name}</span>
                      {r.sub && <span className="block truncate text-xs text-muted">{r.sub}</span>}
                      <span className="block text-xs text-muted">{hoursLabel(weekMinutes(r.id))} this week</span>
                    </th>
                    {days.map((d) => {
                      const items = cell(r.id, d);
                      return (
                        <td key={d} className={cn("px-1.5 py-1.5", d === today && "bg-surface-muted/50")}>
                          <div className="space-y-1">
                            {items.map((s) => (
                              <ShiftChip key={s.id} shift={s} now={now} onClick={() => setEditing({ shift: s })} />
                            ))}
                            {canEdit && r.schedulable && d >= today && (
                              <button
                                type="button"
                                aria-label={`Add shift for ${r.name} on ${formatStayDate(d)}`}
                                onClick={() => setEditing({ initial: { user_id: r.id, date: d } })}
                                className="flex h-7 w-full items-center justify-center rounded-md text-muted opacity-40 hover:bg-surface-muted hover:opacity-100 focus:opacity-100 print:hidden"
                              >
                                <Plus className="size-4" aria-hidden />
                              </button>
                            )}
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <ShiftDialog
        open={!!editing && canEdit}
        onClose={() => setEditing(null)}
        shift={editing?.shift}
        initial={editing?.initial}
        people={people.data ?? []}
        onAttendance={(s) => {
          setEditing(null);
          setAttendanceFor(s);
        }}
      />
      {!canEdit && editing?.shift && <ShiftInfo shift={editing.shift} onClose={() => setEditing(null)} />}
      <AttendanceDialog shift={attendanceFor} onClose={() => setAttendanceFor(null)} />
      <TemplatesDialog open={templatesOpen} onClose={() => setTemplatesOpen(false)} />
      <CopyWeekDialog open={copyOpen} onClose={() => setCopyOpen(false)} target={monday} />
    </>
  );
}

function ShiftChip({ shift, now, onClick }: { shift: Shift; now: number; onClick: () => void }) {
  const badge = attendanceBadge(shift, now);
  return (
    <button
      type="button"
      onClick={onClick}
      title={[shift.template?.name, shift.department?.name, shift.location, shift.notes, badge.label].filter(Boolean).join(" · ")}
      className={cn("block w-full rounded-md border border-l-4 border-border bg-surface px-2 py-1 text-left text-xs hover:bg-surface-muted", TONE_RING[badge.tone])}
    >
      <span className="font-medium tabular-nums">
        {shift.start_time}–{shift.end_time}
        {shift.overnight && <sup>+1</sup>}
      </span>
      <span className="block truncate text-muted">{shift.location || shift.template?.name || shift.department?.name || "Custom"}</span>
      {Date.parse(shift.starts_at) <= now && <span className="block truncate">{badge.label}</span>}
    </button>
  );
}

/** Read-only view for people who can see but not change the rota. */
function ShiftInfo({ shift, onClose }: { shift: Shift; onClose: () => void }) {
  const now = useNow();
  const badge = attendanceBadge(shift, now);
  return (
    <Dialog open onClose={onClose} title={shift.user?.name ?? "Shift"} description={formatStayDate(shift.date, true)}>
      <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-sm">
        <dt className="text-muted">Time</dt>
        <dd>{shift.start_time}–{shift.end_time}{shift.overnight ? " (next day)" : ""}</dd>
        <dt className="text-muted">Shift</dt>
        <dd>{shift.template?.name ?? "Custom"}</dd>
        <dt className="text-muted">Where</dt>
        <dd>{[shift.department?.name, shift.location].filter(Boolean).join(" · ") || "—"}</dd>
        <dt className="text-muted">Attendance</dt>
        <dd><Badge tone={badge.tone}>{badge.label}</Badge></dd>
        {shift.notes && (
          <>
            <dt className="text-muted">Notes</dt>
            <dd>{shift.notes}</dd>
          </>
        )}
      </dl>
    </Dialog>
  );
}

function CopyWeekDialog({ open, onClose, target }: { open: boolean; onClose: () => void; target: string }) {
  const queryClient = useQueryClient();
  const source = addDays(target, -7);
  const copy = useMutation({
    mutationFn: () => teamApi.copyWeek(source, target),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["team"] }),
  });
  const result = copy.data?.data;
  const close = () => {
    copy.reset();
    onClose();
  };

  return (
    <Dialog open={open} onClose={close} title="Copy last week" description={`${formatStayDate(source)} – ${formatStayDate(addDays(source, 6))} → this view's week`}>
      <div className="space-y-4 text-sm">
        {!result ? (
          <p>
            Every shift from the week before is added to the week of {formatStayDate(target, true)}. Clashes and people who have left are skipped.
            Everyone added is emailed.
          </p>
        ) : (
          <>
            <Alert tone="success">{copy.data?.message}</Alert>
            {result.skipped.length > 0 && (
              <ul className="max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-xs text-muted">
                {result.skipped.map((s, i) => (
                  <li key={i}>
                    {s.name}, {formatStayDate(s.date)}: {s.reason}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
        {copy.isError && <Alert tone="danger">{errorMessage(copy.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={close}>{result ? "Done" : "Cancel"}</Button>
          {!result && (
            <Button loading={copy.isPending} onClick={() => copy.mutate()}>
              Copy shifts
            </Button>
          )}
        </div>
      </div>
    </Dialog>
  );
}

