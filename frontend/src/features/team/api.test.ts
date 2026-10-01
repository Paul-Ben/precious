import { describe, expect, it } from "vitest";
import { attendanceBadge, fromLocalInput, hotelTime, hoursLabel, teamApi, toLocalInput, weekDays, weekStart } from "./api";

describe("weeks", () => {
  it("finds the Monday of a week", () => {
    expect(weekStart("2026-10-05")).toBe("2026-10-05"); // Monday
    expect(weekStart("2026-10-11")).toBe("2026-10-05"); // Sunday
    expect(weekStart("2026-10-01")).toBe("2026-09-28"); // Thursday, across a month
  });

  it("lists the seven days", () => {
    expect(weekDays("2026-12-28")).toEqual(["2026-12-28", "2026-12-29", "2026-12-30", "2026-12-31", "2027-01-01", "2027-01-02", "2027-01-03"]);
  });
});

describe("times", () => {
  it("formats hours", () => {
    expect(hoursLabel(0)).toBe("0h");
    expect(hoursLabel(480)).toBe("8h");
    expect(hoursLabel(165)).toBe("2h 45m");
    expect(hoursLabel(119.7)).toBe("2h");
  });

  it("shows timestamps in hotel time (UTC+1)", () => {
    expect(hotelTime("2026-10-05T06:05:00Z")).toBe("07:05");
    expect(hotelTime(null)).toBe("—");
    expect(toLocalInput("2026-10-05T23:30:00Z")).toBe("2026-10-06T00:30");
    expect(fromLocalInput("2026-10-06T00:30")).toBe("2026-10-06 00:30");
    expect(fromLocalInput("nonsense")).toBeNull();
  });
});

describe("attendanceBadge", () => {
  const now = Date.parse("2026-10-05T09:00:00Z");
  const base = { starts_at: "2026-10-05T07:00:00Z" };

  it("describes each state", () => {
    expect(attendanceBadge({ ...base, attendance: null }, now)).toEqual({ label: "Not clocked in", tone: "warning" });
    expect(attendanceBadge({ starts_at: "2026-10-06T07:00:00Z", attendance: null }, now).label).toBe("Scheduled");

    const record = {
      id: "a", clock_in_at: null, clock_out_at: null, is_late: false, late_minutes: 0, worked_minutes: 0,
      auto_closed: false, needs_review: false, correction_reason: null, corrected_at: null,
    };
    expect(attendanceBadge({ ...base, attendance: { ...record, status: "ABSENT" } }, now).tone).toBe("danger");
    expect(attendanceBadge({ ...base, attendance: { ...record, status: "CLOCKED_IN", is_late: true, late_minutes: 12 } }, now).label).toBe("On shift · 12m late");
    expect(attendanceBadge({ ...base, attendance: { ...record, status: "CLOCKED_OUT" } }, now)).toEqual({ label: "Present", tone: "success" });
    expect(attendanceBadge({ ...base, attendance: { ...record, status: "CLOCKED_OUT", needs_review: true } }, now).label).toBe("Check clock-out");
  });
});

describe("teamApi URLs", () => {
  it("builds BFF links", () => {
    expect(teamApi.exportUrl("2026-10-01", "2026-10-31")).toBe("/api/bff/attendance/export?from=2026-10-01&to=2026-10-31");
    expect(teamApi.photoUrl("abc", "1234")).toBe("/api/bff/staff/abc/photo?v=1234");
  });
});
