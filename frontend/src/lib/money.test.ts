import { describe, expect, it } from "vitest";
import { addDays, formatStayDate, nightsBetween } from "./dates";
import { formatNaira, formatPercent } from "./money";

describe("formatNaira", () => {
  it("formats API decimal strings without floating point", () => {
    expect(formatNaira("150000.00")).toBe("₦150,000");
    expect(formatNaira("85500.50")).toBe("₦85,500.50");
    expect(formatNaira("0.00", { kobo: true })).toBe("₦0.00");
    expect(formatNaira("-45000.00")).toBe("-₦45,000");
    expect(formatNaira("12345678901.99")).toBe("₦12,345,678,901.99");
    expect(formatNaira(null)).toBe("—");
  });

  it("formats percentages", () => {
    expect(formatPercent("7.5")).toBe("7.5%");
    expect(formatPercent("30.00")).toBe("30%");
  });
});

describe("stay dates", () => {
  it("adds days across month ends and counts nights", () => {
    expect(addDays("2026-10-30", 3)).toBe("2026-11-02");
    expect(nightsBetween("2026-10-16", "2026-10-19")).toBe(3);
    expect(formatStayDate("2026-10-16")).toMatch(/16 Oct/);
  });
});
