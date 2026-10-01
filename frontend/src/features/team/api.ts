import { api, bffUrl } from "@/lib/api/client";
import { addDays } from "@/lib/dates";

// ------------------------------------------------------------------ types

export type EmploymentStatus = "ACTIVE" | "ON_LEAVE" | "LEFT";
export type AttendanceStatus = "CLOCKED_IN" | "CLOCKED_OUT" | "ABSENT";

export interface Department {
  id: number;
  name: string;
  code: string;
}

export interface StaffMember {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  account_status: "active" | "suspended";
  roles: string[];
  employee_number: string | null;
  department: { id: number; name: string } | null;
  position: string | null;
  employment_status: EmploymentStatus;
  employment_status_label: string;
  start_date: string | null;
  end_date: string | null;
  address: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  has_photo: boolean;
  photo_version: string | null;
  notes: string | null;
}

export type StaffUpdate = Partial<
  Pick<
    StaffMember,
    "position" | "employment_status" | "start_date" | "end_date" | "address" | "emergency_contact_name" | "emergency_contact_phone" | "notes"
  > & { department_id: number | null }
>;

export interface ShiftTemplate {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  overnight: boolean;
  is_active: boolean;
}

export interface Attendance {
  id: string;
  status: AttendanceStatus;
  clock_in_at: string | null;
  clock_out_at: string | null;
  is_late: boolean;
  late_minutes: number;
  worked_minutes: number;
  auto_closed: boolean;
  needs_review: boolean;
  correction_reason: string | null;
  corrected_by?: string | null;
  corrected_at: string | null;
}

export interface Colleague {
  name: string;
  department: string | null;
  location: string | null;
  start_time: string;
  end_time: string;
}

export interface Shift {
  id: string;
  date: string;
  starts_at: string;
  ends_at: string;
  start_time: string;
  end_time: string;
  overnight: boolean;
  minutes: number;
  status: "SCHEDULED" | "CANCELLED";
  user?: { id: string; name: string; employee_number: string | null };
  template?: { id: number; name: string } | null;
  department?: { id: number; name: string } | null;
  location: string | null;
  notes: string | null;
  cancel_reason: string | null;
  attendance?: Attendance | null;
  can_clock_in?: boolean;
  can_clock_out?: boolean;
  colleagues?: Colleague[];
}

export interface ShiftInput {
  user_id?: string;
  date?: string;
  template_id?: number | null;
  start_time?: string | null;
  end_time?: string | null;
  department_id?: number | null;
  location?: string | null;
  notes?: string | null;
}

export interface AttendanceSummaryRow {
  user_id: string;
  name: string;
  employee_number: string | null;
  department: string | null;
  shifts: number;
  scheduled_minutes: number;
  worked_minutes: number;
  late: number;
  late_minutes: number;
  absent: number;
  needs_review: number;
}

export interface AttendanceSummary {
  from: string;
  to: string;
  people: AttendanceSummaryRow[];
  totals: { shifts: number; worked_minutes: number; late: number; absent: number; needs_review: number };
}

export interface StaffFilters {
  search?: string;
  department_id?: number | "";
  employment_status?: EmploymentStatus | "";
  page?: number;
}

// ------------------------------------------------------------------ api

export const teamKeys = {
  departments: ["team", "departments"] as const,
  list: (f: StaffFilters) => ["team", "list", f] as const,
  schedulable: ["team", "schedulable"] as const,
  member: (id: string) => ["team", "member", id] as const,
  templates: ["team", "templates"] as const,
  shifts: (from: string, to: string) => ["team", "shifts", from, to] as const,
  myShifts: ["team", "my-shifts"] as const,
  attendance: (from: string, to: string, exceptions: boolean) => ["team", "attendance", from, to, exceptions] as const,
  summary: (from: string, to: string) => ["team", "attendance-summary", from, to] as const,
};

export const teamApi = {
  departments: async () => (await api.get<Department[]>("departments")).data,
  list: (f: StaffFilters, signal?: AbortSignal) => api.list<StaffMember>("staff", { ...f, per_page: 25 }, signal),
  /** Everyone who can be put on the rota (active, not left). */
  schedulable: async () => (await api.get<StaffMember[]>("staff", { all: 1 })).data,
  member: async (id: string) => (await api.get<StaffMember>(`staff/${id}`)).data,
  update: (id: string, body: StaffUpdate) => api.patch<StaffMember>(`staff/${id}`, body),
  uploadPhoto: (id: string, file: File) => {
    const form = new FormData();
    form.append("photo", file);
    return api.upload<StaffMember>(`staff/${id}/photo`, form);
  },
  deletePhoto: (id: string) => api.delete<null>(`staff/${id}/photo`),
  photoUrl: (id: string, version: string | null) => bffUrl(`staff/${id}/photo`, { v: version ?? undefined }),

  templates: async () => (await api.get<ShiftTemplate[]>("shift-templates")).data,
  createTemplate: (body: Pick<ShiftTemplate, "name" | "start_time" | "end_time">) => api.post<ShiftTemplate>("shift-templates", body),
  updateTemplate: (id: number, body: Partial<Pick<ShiftTemplate, "name" | "start_time" | "end_time" | "is_active">>) =>
    api.patch<ShiftTemplate>(`shift-templates/${id}`, body),
  deleteTemplate: (id: number) => api.delete<null>(`shift-templates/${id}`),

  shifts: async (from: string, to: string) => (await api.get<Shift[]>("shifts", { from, to })).data,
  createShift: (body: ShiftInput) => api.post<Shift>("shifts", body),
  updateShift: (id: string, body: ShiftInput) => api.patch<Shift>(`shifts/${id}`, body),
  cancelShift: (id: string, reason?: string) => api.post<Shift>(`shifts/${id}/cancel`, { reason: reason || undefined }),
  copyWeek: (fromWeek: string, toWeek: string) =>
    api.post<{ created: number; skipped: { name: string; date: string; reason: string }[] }>("shifts/copy-week", {
      from_week: fromWeek,
      to_week: toWeek,
    }),
  correctAttendance: (id: string, body: { absent?: boolean; clock_in_at?: string | null; clock_out_at?: string | null; reason: string }) =>
    api.put<Shift>(`shifts/${id}/attendance`, body),

  myShifts: async () => (await api.get<Shift[]>("my-shifts")).data,
  clockIn: (id: string) => api.post<Shift>(`my-shifts/${id}/clock-in`),
  clockOut: (id: string) => api.post<Shift>(`my-shifts/${id}/clock-out`),

  attendance: async (from: string, to: string, exceptions: boolean) =>
    (await api.get<Shift[]>("attendance", { from, to, exceptions: exceptions ? 1 : undefined })).data,
  summary: async (from: string, to: string) => (await api.get<AttendanceSummary>("attendance/summary", { from, to })).data,
  exportUrl: (from: string, to: string) => bffUrl("attendance/export", { from, to }),
};

// ------------------------------------------------------------------ helpers

/** Monday of the week containing `date` (YYYY-MM-DD). */
export function weekStart(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  const day = new Date(Date.UTC(y!, m! - 1, d!)).getUTCDay(); // 0 = Sunday
  return addDays(date, -((day + 6) % 7));
}

/** The seven dates of the week starting on `monday`. */
export function weekDays(monday: string): string[] {
  return Array.from({ length: 7 }, (_, i) => addDays(monday, i));
}

/** 450 → "7h 30m", 480 → "8h", 0 → "0h". */
export function hoursLabel(minutes: number): string {
  const total = Math.round(minutes);
  const h = Math.floor(total / 60);
  const m = total % 60;
  return m ? `${h}h ${m}m` : `${h}h`;
}

const timeFormat = new Intl.DateTimeFormat("en-GB", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Africa/Lagos" });
const localFormat = new Intl.DateTimeFormat("en-CA", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hour12: false,
  timeZone: "Africa/Lagos",
});

/** ISO timestamp → "07:05" in hotel time. */
export function hotelTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : timeFormat.format(d);
}

/** ISO timestamp → "2026-10-05T07:05" (hotel time) for <input type="datetime-local">. */
export function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const parts = Object.fromEntries(localFormat.formatToParts(d).map((p) => [p.type, p.value]));
  const hour = parts.hour === "24" ? "00" : parts.hour;
  return `${parts.year}-${parts.month}-${parts.day}T${hour}:${parts.minute}`;
}

/** "2026-10-05T07:05" → "2026-10-05 07:05" (API format). */
export function fromLocalInput(value: string): string | null {
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) ? value.replace("T", " ") : null;
}

export type AttendanceTone = "success" | "warning" | "danger" | "info" | "neutral";

/** Short label + badge tone for a shift's attendance. */
export function attendanceBadge(shift: Pick<Shift, "attendance" | "starts_at">, now: number): { label: string; tone: AttendanceTone } {
  const a = shift.attendance;
  if (!a) return Date.parse(shift.starts_at) <= now ? { label: "Not clocked in", tone: "warning" } : { label: "Scheduled", tone: "neutral" };
  if (a.status === "ABSENT") return { label: "Absent", tone: "danger" };
  if (a.status === "CLOCKED_IN") return { label: a.is_late ? `On shift · ${a.late_minutes}m late` : "On shift", tone: "info" };
  if (a.needs_review) return { label: "Check clock-out", tone: "warning" };
  return a.is_late ? { label: `Late ${a.late_minutes}m`, tone: "warning" } : { label: "Present", tone: "success" };
}

export const EMPLOYMENT_LABELS: Record<EmploymentStatus, string> = { ACTIVE: "Active", ON_LEAVE: "On leave", LEFT: "Left" };
