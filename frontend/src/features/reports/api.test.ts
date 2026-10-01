import { describe, expect, it } from "vitest";
import { presetRange, reportsApi } from "./api";

describe("presetRange", () => {
  const today = "2026-10-05";

  it("resolves rolling windows inclusive of today", () => {
    expect(presetRange("today", today)).toEqual({ from: today, to: today });
    expect(presetRange("7d", today)).toEqual({ from: "2026-09-29", to: today });
    expect(presetRange("30d", today)).toEqual({ from: "2026-09-06", to: today });
  });

  it("resolves calendar months, including across a year boundary", () => {
    expect(presetRange("month", today)).toEqual({ from: "2026-10-01", to: today });
    expect(presetRange("last-month", today)).toEqual({ from: "2026-09-01", to: "2026-09-30" });
    expect(presetRange("last-month", "2027-01-15")).toEqual({ from: "2026-12-01", to: "2026-12-31" });
  });
});

describe("reportsApi.exportUrl", () => {
  it("points at the BFF with the range", () => {
    expect(reportsApi.exportUrl("bar-sales", { from: "2026-10-01", to: "2026-10-05" })).toBe(
      "/api/bff/reports/export?type=bar-sales&from=2026-10-01&to=2026-10-05",
    );
  });
});
