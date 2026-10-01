"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { formatStayDate } from "@/lib/dates";
import { useNow } from "./use-now";
import { type Shift, type ShiftInput, type StaffMember, attendanceBadge, hoursLabel, teamApi, teamKeys } from "./api";

const CUSTOM = "custom";

/**
 * Add a shift (pass `initial` with user and date) or edit/cancel one (pass `shift`).
 * Started shifts only allow notes/location; their attendance is handled in AttendanceDialog.
 */
export function ShiftDialog({
  open,
  onClose,
  shift,
  initial,
  people,
  onAttendance,
}: {
  open: boolean;
  onClose: () => void;
  shift?: Shift | null;
  initial?: { user_id?: string; date?: string };
  people: StaffMember[];
  onAttendance?: (shift: Shift) => void;
}) {
  return (
    <Dialog open={open} onClose={onClose} title={shift ? "Shift" : "Add shift"} description={shift ? `${shift.user?.name} · ${formatStayDate(shift.date, true)}` : undefined}>
      {open && <Body key={shift?.id ?? `${initial?.user_id}-${initial?.date}`} shift={shift ?? null} initial={initial} people={people} onClose={onClose} onAttendance={onAttendance} />}
    </Dialog>
  );
}

function Body({
  shift,
  initial,
  people,
  onClose,
  onAttendance,
}: {
  shift: Shift | null;
  initial?: { user_id?: string; date?: string };
  people: StaffMember[];
  onClose: () => void;
  onAttendance?: (shift: Shift) => void;
}) {
  const queryClient = useQueryClient();
  const templates = useQuery({ queryKey: teamKeys.templates, queryFn: teamApi.templates });
  const departments = useQuery({ queryKey: teamKeys.departments, queryFn: teamApi.departments });
  const now = useNow();
  const started = shift ? Date.parse(shift.starts_at) <= now : false;
  const person = (id: string) => people.find((p) => p.id === id);

  const [form, setForm] = useState(() => ({
    user_id: shift?.user?.id ?? initial?.user_id ?? "",
    date: shift?.date ?? initial?.date ?? "",
    template: shift ? (shift.template ? String(shift.template.id) : CUSTOM) : "",
    start_time: shift?.start_time ?? "",
    end_time: shift?.end_time ?? "",
    // New shift: default to the person's own department (the backend does the same when none is sent).
    department_id: shift
      ? shift.department?.id
        ? String(shift.department.id)
        : ""
      : String(people.find((p) => p.id === initial?.user_id)?.department?.id ?? ""),
    location: shift?.location ?? "",
    notes: shift?.notes ?? "",
  }));
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  // New shift: follow the chosen person's department. Edits keep the shift's department.
  const pickPerson = (id: string) =>
    setForm((f) => ({ ...f, user_id: id, department_id: shift ? f.department_id : String(person(id)?.department?.id ?? "") }));

  const activeTemplates = (templates.data ?? []).filter((t) => t.is_active || String(t.id) === form.template);
  const custom = form.template === CUSTOM;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["team"] });

  const save = useMutation({
    mutationFn: () => {
      const body: ShiftInput = {
        department_id: form.department_id ? Number(form.department_id) : null,
        location: form.location.trim() || null,
        notes: form.notes.trim() || null,
      };
      // Timing is only sent for new shifts or when it actually changed, so saving notes never moves a shift.
      const timingChanged =
        !shift ||
        form.user_id !== shift.user?.id ||
        form.date !== shift.date ||
        form.template !== (shift.template ? String(shift.template.id) : CUSTOM) ||
        (custom && (form.start_time !== shift.start_time || form.end_time !== shift.end_time));
      if (!started && timingChanged) {
        body.user_id = form.user_id;
        body.date = form.date;
        if (custom) {
          body.template_id = null;
          body.start_time = form.start_time;
          body.end_time = form.end_time;
        } else {
          body.template_id = Number(form.template);
        }
      }
      return shift ? teamApi.updateShift(shift.id, body) : teamApi.createShift(body);
    },
    onSuccess: async () => {
      await invalidate();
      onClose();
    },
  });

  const cancel = useMutation({
    mutationFn: () => teamApi.cancelShift(shift!.id, reason.trim()),
    onSuccess: async () => {
      await invalidate();
      onClose();
    },
  });

  const fieldError = (name: string) => (isApiError(save.error) ? save.error.errors?.[name]?.[0] : undefined);
  const ready = form.user_id && form.date && (custom ? form.start_time && form.end_time : form.template);

  if (cancelling && shift) {
    return (
      <div className="space-y-4">
        <p className="text-sm">
          Cancel {shift.user?.name}&rsquo;s shift on {formatStayDate(shift.date)} ({shift.start_time}–{shift.end_time})? They will be emailed.
        </p>
        <Field label="Reason (optional, included in the email)">
          <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
        </Field>
        {cancel.isError && <Alert tone="danger">{errorMessage(cancel.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setCancelling(false)}>Back</Button>
          <Button variant="danger" loading={cancel.isPending} onClick={() => cancel.mutate()}>Cancel shift</Button>
        </div>
      </div>
    );
  }

  const badge = shift ? attendanceBadge(shift, now) : null;

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      {started && (
        <Alert tone="info">
          This shift has started ({badge?.label}). Only the place and notes can change.
          {onAttendance && shift && (
            <>
              {" "}
              <button type="button" className="font-medium underline" onClick={() => onAttendance(shift)}>
                Record or correct attendance
              </button>
            </>
          )}
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Person" required error={fieldError("user_id")}>
          <Select value={form.user_id} disabled={started} onChange={(e) => pickPerson(e.target.value)}>
            <option value="" disabled>Choose…</option>
            {people.map((p) => (
              <option key={p.id} value={p.id}>{p.name}{p.position ? ` — ${p.position}` : ""}</option>
            ))}
            {shift?.user && !person(shift.user.id) && <option value={shift.user.id}>{shift.user.name}</option>}
          </Select>
        </Field>
        <Field label="Date" required error={fieldError("date")}>
          <Input type="date" value={form.date} disabled={started} onChange={(e) => set("date")(e.target.value)} />
        </Field>
        <Field label="Shift" required error={fieldError("template_id")} className="sm:col-span-2">
          <Select value={form.template} disabled={started} onChange={(e) => set("template")(e.target.value)}>
            <option value="" disabled>Choose…</option>
            {activeTemplates.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} · {t.start_time}–{t.end_time}{t.overnight ? " (next day)" : ""}
              </option>
            ))}
            <option value={CUSTOM}>Custom times…</option>
          </Select>
        </Field>
        {custom && (
          <>
            <Field label="Starts" required error={fieldError("start_time")}>
              <Input type="time" value={form.start_time} disabled={started} onChange={(e) => set("start_time")(e.target.value)} />
            </Field>
            <Field
              label="Ends"
              required
              error={fieldError("end_time")}
              hint={form.start_time && form.end_time && form.end_time <= form.start_time ? "Ends the next day." : undefined}
            >
              <Input type="time" value={form.end_time} disabled={started} onChange={(e) => set("end_time")(e.target.value)} />
            </Field>
          </>
        )}
        <Field label="Department" error={fieldError("department_id")}>
          <Select value={form.department_id} onChange={(e) => set("department_id")(e.target.value)}>
            <option value="">—</option>
            {departments.data?.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </Select>
        </Field>
        <Field label="Location" error={fieldError("location")}>
          <Input value={form.location} maxLength={80} placeholder="e.g. Pool bar, Front desk" onChange={(e) => set("location")(e.target.value)} />
        </Field>
        <Field label="Notes" error={fieldError("notes")} className="sm:col-span-2">
          <Input value={form.notes} maxLength={500} onChange={(e) => set("notes")(e.target.value)} />
        </Field>
      </div>

      {shift && <p className="text-xs text-muted">Length {hoursLabel(shift.minutes)}. Changes to time or place are emailed.</p>}
      {save.isError && <Alert tone="danger">{errorMessage(save.error)}</Alert>}

      <div className="flex flex-wrap items-center justify-between gap-2">
        {shift && !started ? (
          <Button variant="ghost" className="text-danger" onClick={() => setCancelling(true)}>Cancel shift</Button>
        ) : (
          <span />
        )}
        <div className="flex gap-2">
          <Button variant="ghost" onClick={onClose}>Close</Button>
          <Button type="submit" loading={save.isPending} disabled={!ready}>{shift ? "Save" : "Add shift"}</Button>
        </div>
      </div>
    </form>
  );
}
