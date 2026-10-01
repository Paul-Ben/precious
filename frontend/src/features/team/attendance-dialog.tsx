"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { errorMessage } from "@/lib/api/errors";
import { formatStayDate } from "@/lib/dates";
import { formatDateTime } from "@/lib/utils";
import { type Shift, attendanceBadge, fromLocalInput, teamApi, toLocalInput } from "./api";
import { useNow } from "./use-now";

/** P25: record or correct someone's attendance for a shift that has started. A reason is required and audited. */
export function AttendanceDialog({ shift, onClose }: { shift: Shift | null; onClose: () => void }) {
  return (
    <Dialog
      open={!!shift}
      onClose={onClose}
      title="Attendance"
      description={shift ? `${shift.user?.name} · ${formatStayDate(shift.date, true)} · ${shift.start_time}–${shift.end_time}` : undefined}
    >
      {shift && <Body key={shift.id} shift={shift} onClose={onClose} />}
    </Dialog>
  );
}

function Body({ shift, onClose }: { shift: Shift; onClose: () => void }) {
  const queryClient = useQueryClient();
  const a = shift.attendance;
  const [absent, setAbsent] = useState(a?.status === "ABSENT");
  const [clockIn, setClockIn] = useState(toLocalInput(a?.clock_in_at) || toLocalInput(shift.starts_at));
  const [clockOut, setClockOut] = useState(toLocalInput(a?.clock_out_at));
  const [reason, setReason] = useState("");
  const now = useNow();
  const badge = attendanceBadge(shift, now);
  const latest = toLocalInput(new Date(now).toISOString()); // the API rejects times in the future

  const save = useMutation({
    mutationFn: () =>
      teamApi.correctAttendance(
        shift.id,
        absent
          ? { absent: true, reason: reason.trim() }
          : { clock_in_at: fromLocalInput(clockIn), clock_out_at: clockOut ? fromLocalInput(clockOut) : null, reason: reason.trim() },
      ),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["team"] });
      onClose();
    },
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <p className="text-sm">
        Now: <span className="font-medium">{badge.label}</span>
        {a?.auto_closed && " — clock-out was not recorded, so it was closed at the shift end."}
      </p>
      {a?.correction_reason && (
        <p className="text-xs text-muted">
          Last corrected {formatDateTime(a.corrected_at)}{a.corrected_by ? ` by ${a.corrected_by}` : ""}: {a.correction_reason}
        </p>
      )}

      <Checkbox label="Absent — did not work this shift" checked={absent} onChange={(e) => setAbsent(e.target.checked)} />

      {!absent && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Clocked in" required>
            <Input type="datetime-local" value={clockIn} max={latest} onChange={(e) => setClockIn(e.target.value)} />
          </Field>
          <Field label="Clocked out" hint="Leave empty if still on shift.">
            <Input type="datetime-local" value={clockOut} min={clockIn || undefined} max={latest} onChange={(e) => setClockOut(e.target.value)} />
          </Field>
        </div>
      )}

      <Field label="Reason" required hint="Kept in the audit log.">
        <Input value={reason} minLength={3} maxLength={255} placeholder="e.g. Forgot to clock in; confirmed by supervisor" onChange={(e) => setReason(e.target.value)} />
      </Field>

      {save.isError && <Alert tone="danger">{errorMessage(save.error)}</Alert>}

      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Close</Button>
        <Button type="submit" loading={save.isPending} disabled={reason.trim().length < 3 || (!absent && !clockIn)}>Save</Button>
      </div>
    </form>
  );
}
