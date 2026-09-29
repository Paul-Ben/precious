import { describe, expect, it } from "vitest";
import { minutesSince } from "./api";

describe("minutesSince", () => {
  const now = Date.parse("2026-10-05T20:30:00Z");

  it("shows 'just now' under a minute", () => {
    expect(minutesSince("2026-10-05T20:29:30Z", now)).toBe("just now");
  });

  it("shows whole minutes", () => {
    expect(minutesSince("2026-10-05T20:12:00Z", now)).toBe("18 min");
  });

  it("is empty without a time", () => {
    expect(minutesSince(null, now)).toBe("");
  });
});
