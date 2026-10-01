"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Camera, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRef, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { formatStayDate } from "@/lib/dates";
import { EMPLOYMENT_LABELS, type EmploymentStatus, type StaffMember, type StaffUpdate, teamApi, teamKeys } from "./api";
import { StaffAvatar } from "./staff-avatar";

export function StaffRecord({ id }: { id: string }) {
  return (
    <RequirePermission permission="staff.view">
      <Inner id={id} />
    </RequirePermission>
  );
}

function Inner({ id }: { id: string }) {
  const q = useQuery({ queryKey: teamKeys.member(id), queryFn: () => teamApi.member(id) });

  return (
    <>
      <Link href="/staff/team" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> Staff
      </Link>
      {q.isPending ? <LoadingState /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : <Record member={q.data} />}
    </>
  );
}

function Record({ member }: { member: StaffMember }) {
  const { can, user } = useSession();
  const editable = can("staff.update");

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-4">
        <StaffAvatar member={member} size="lg" />
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight">{member.name}</h1>
          <p className="text-sm text-muted">
            <span className="font-mono">{member.employee_number}</span>
            {member.position && <> · {member.position}</>}
            {member.department && <> · {member.department.name}</>}
          </p>
          <div className="mt-2 flex flex-wrap gap-1">
            <Badge tone={member.employment_status === "ACTIVE" ? "success" : member.employment_status === "ON_LEAVE" ? "warning" : "neutral"}>
              {member.employment_status_label}
            </Badge>
            {member.account_status === "suspended" && <Badge tone="danger">Sign-in blocked</Badge>}
            {member.roles.map((r) => (
              <Badge key={r}>{r}</Badge>
            ))}
          </div>
        </div>
        <div className="ml-auto flex flex-wrap gap-2">
          {editable && <PhotoButtons member={member} />}
          {can("users.view") && (
            <Link href={`/staff/users/${member.id}`} className="inline-flex h-9 items-center rounded-lg border border-border px-3 text-sm font-medium hover:bg-surface-muted">
              Account &amp; roles
            </Link>
          )}
        </div>
      </div>

      {editable ? (
        <EditForm key={member.id} member={member} isSelf={user.id === member.id} />
      ) : (
        <Card>
          <CardHeader title="Details" />
          <dl className="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
            {[
              ["Email", member.email],
              ["Phone", member.phone],
              ["Start date", member.start_date && formatStayDate(member.start_date, true)],
              ["End date", member.end_date && formatStayDate(member.end_date, true)],
              ["Address", member.address],
              ["Emergency contact", [member.emergency_contact_name, member.emergency_contact_phone].filter(Boolean).join(" · ")],
              ["Notes", member.notes],
            ].map(([k, v]) => (
              <div key={k}>
                <dt className="text-xs uppercase tracking-wider text-muted">{k}</dt>
                <dd className="mt-0.5 whitespace-pre-line">{v || "—"}</dd>
              </div>
            ))}
          </dl>
        </Card>
      )}
    </div>
  );
}

function PhotoButtons({ member }: { member: StaffMember }) {
  const queryClient = useQueryClient();
  const input = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["team"] });

  const upload = useMutation({
    mutationFn: (file: File) => teamApi.uploadPhoto(member.id, file),
    onSuccess: refresh,
    onError: (e) => setError(errorMessage(e)),
  });
  const remove = useMutation({
    mutationFn: () => teamApi.deletePhoto(member.id),
    onMutate: () => setError(null),
    onSuccess: refresh,
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <>
      <input
        ref={input}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="sr-only"
        aria-label="Photo"
        onChange={(e) => {
          const file = e.target.files?.[0];
          e.target.value = "";
          if (!file) return;
          if (file.size > 4 * 1024 * 1024) return setError("Choose a photo under 4 MB.");
          setError(null);
          upload.mutate(file);
        }}
      />
      <Button variant="outline" size="sm" loading={upload.isPending} onClick={() => input.current?.click()}>
        <Camera className="size-4" aria-hidden /> {member.has_photo ? "Change photo" : "Add photo"}
      </Button>
      {member.has_photo && (
        <Button variant="ghost" size="sm" loading={remove.isPending} onClick={() => remove.mutate()} aria-label="Remove photo">
          <Trash2 className="size-4" aria-hidden />
        </Button>
      )}
      {error && <p className="w-full text-sm text-danger" role="alert">{error}</p>}
    </>
  );
}

function EditForm({ member, isSelf }: { member: StaffMember; isSelf: boolean }) {
  const queryClient = useQueryClient();
  const departments = useQuery({ queryKey: teamKeys.departments, queryFn: teamApi.departments });
  const [form, setForm] = useState({
    department_id: member.department?.id ? String(member.department.id) : "",
    position: member.position ?? "",
    employment_status: member.employment_status,
    start_date: member.start_date ?? "",
    end_date: member.end_date ?? "",
    address: member.address ?? "",
    emergency_contact_name: member.emergency_contact_name ?? "",
    emergency_contact_phone: member.emergency_contact_phone ?? "",
    notes: member.notes ?? "",
  });
  const [saved, setSaved] = useState(false);
  const set = (key: keyof typeof form) => (value: string) => {
    setSaved(false);
    setForm((f) => ({ ...f, [key]: value }));
  };
  const leaving = form.employment_status === "LEFT" && member.employment_status !== "LEFT";

  const save = useMutation({
    mutationFn: () => {
      const body: StaffUpdate = {
        department_id: form.department_id ? Number(form.department_id) : null,
        position: form.position.trim() || null,
        employment_status: form.employment_status,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        address: form.address.trim() || null,
        emergency_contact_name: form.emergency_contact_name.trim() || null,
        emergency_contact_phone: form.emergency_contact_phone.trim() || null,
        notes: form.notes.trim() || null,
      };
      return teamApi.update(member.id, body);
    },
    onSuccess: async () => {
      setSaved(true);
      // Marking someone as left also suspends their account (Users) and cancels their future shifts.
      await Promise.all([queryClient.invalidateQueries({ queryKey: ["team"] }), queryClient.invalidateQueries({ queryKey: ["users"] })]);
    },
  });
  const fieldError = (name: string) => (isApiError(save.error) ? save.error.errors?.[name]?.[0] : undefined);

  return (
    <Card>
      <CardHeader title="Employee record" description="Account details (name, email, phone) are edited under Account & roles." />
      <form
        className="space-y-4 p-5"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Department" error={fieldError("department_id")}>
            <Select value={form.department_id} onChange={(e) => set("department_id")(e.target.value)}>
              <option value="">No department</option>
              {departments.data?.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </Select>
          </Field>
          <Field label="Position" error={fieldError("position")}>
            <Input value={form.position} maxLength={80} placeholder="e.g. Senior waiter" onChange={(e) => set("position")(e.target.value)} />
          </Field>
          <Field label="Employment status" error={fieldError("employment_status")}>
            <Select value={form.employment_status} disabled={isSelf} onChange={(e) => set("employment_status")(e.target.value as EmploymentStatus)}>
              {(Object.keys(EMPLOYMENT_LABELS) as EmploymentStatus[]).map((s) => (
                <option key={s} value={s}>{EMPLOYMENT_LABELS[s]}</option>
              ))}
            </Select>
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Start date" error={fieldError("start_date")}>
              <Input type="date" value={form.start_date} onChange={(e) => set("start_date")(e.target.value)} />
            </Field>
            <Field label="End date" error={fieldError("end_date")}>
              <Input type="date" value={form.end_date} min={form.start_date || undefined} onChange={(e) => set("end_date")(e.target.value)} />
            </Field>
          </div>
          <Field label="Address" error={fieldError("address")} className="sm:col-span-2">
            <Input value={form.address} maxLength={255} onChange={(e) => set("address")(e.target.value)} />
          </Field>
          <Field label="Emergency contact" error={fieldError("emergency_contact_name")}>
            <Input value={form.emergency_contact_name} maxLength={120} placeholder="Name" onChange={(e) => set("emergency_contact_name")(e.target.value)} />
          </Field>
          <Field label="Emergency phone" error={fieldError("emergency_contact_phone")}>
            <Input type="tel" value={form.emergency_contact_phone} maxLength={32} onChange={(e) => set("emergency_contact_phone")(e.target.value)} />
          </Field>
          <Field label="Notes" error={fieldError("notes")} className="sm:col-span-2" hint="Visible to managers only.">
            <textarea
              rows={3}
              maxLength={2000}
              value={form.notes}
              onChange={(e) => set("notes")(e.target.value)}
              className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
          </Field>
        </div>

        {leaving && (
          <Alert tone="warning" title="Marking as left">
            {member.name} will no longer be able to sign in, and their future shifts will be cancelled.
          </Alert>
        )}
        {save.isError && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
        {saved && <Alert tone="success">Saved.</Alert>}

        <div className="flex justify-end">
          <Button type="submit" loading={save.isPending}>Save</Button>
        </div>
      </form>
    </Card>
  );
}
