"use client";

import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { todayInHotel } from "@/lib/dates";
import { cn } from "@/lib/utils";
import { RANGES, type RangeKey, rangeFor } from "./api";

export interface RangeState {
  key: RangeKey;
  from: string;
  to: string;
}

export function initialRange(key: Exclude<RangeKey, "custom"> = "month"): RangeState {
  return { key, ...rangeFor(key) };
}

/** Preset buttons + from/to dates (hotel calendar). */
export function RangePicker({ value, onChange }: { value: RangeState; onChange: (v: RangeState) => void }) {
  const today = todayInHotel();
  const setDate = (field: "from" | "to") => (date: string) => {
    if (!date) return;
    const next = { ...value, key: "custom" as const, [field]: date };
    onChange(next.from > next.to ? { key: "custom", from: date, to: date } : next);
  };

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div role="group" aria-label="Date range" className="inline-flex flex-wrap rounded-lg bg-surface-muted p-1">
        {RANGES.map((r) => (
          <button
            key={r.key}
            type="button"
            aria-pressed={value.key === r.key}
            onClick={() => onChange({ key: r.key, ...rangeFor(r.key) })}
            className={cn("rounded-md px-3 py-1.5 text-sm font-medium", value.key === r.key ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
          >
            {r.label}
          </button>
        ))}
      </div>
      <Field label="From">
        <Input type="date" value={value.from} max={today} onChange={(e) => setDate("from")(e.target.value)} />
      </Field>
      <Field label="To">
        <Input type="date" value={value.to} max={today} onChange={(e) => setDate("to")(e.target.value)} />
      </Field>
    </div>
  );
}
