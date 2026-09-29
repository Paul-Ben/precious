"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { addDays, todayInHotel } from "@/lib/dates";
import { cn } from "@/lib/utils";

export interface SearchDefaults {
  check_in?: string;
  check_out?: string;
  adults?: number;
  children?: number;
}

/**
 * Stay search (dates + party). Submits to /rooms?check_in=…&check_out=…
 */
export function SearchForm({ defaults, variant = "card", className }: { defaults?: SearchDefaults; variant?: "card" | "bar"; className?: string }) {
  const router = useRouter();
  const today = todayInHotel();
  const [checkIn, setCheckIn] = useState(defaults?.check_in ?? addDays(today, 1));
  const [checkOut, setCheckOut] = useState(defaults?.check_out ?? addDays(today, 2));
  const [adults, setAdults] = useState(defaults?.adults ?? 2);
  const [children, setChildren] = useState(defaults?.children ?? 0);
  const [error, setError] = useState<string | null>(null);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!checkIn || !checkOut || checkOut <= checkIn) {
      setError("Check-out must be after check-in.");
      return;
    }
    if (checkIn < today) {
      setError("Check-in can't be in the past.");
      return;
    }
    setError(null);
    const params = new URLSearchParams({ check_in: checkIn, check_out: checkOut, adults: String(adults), children: String(children) });
    router.push(`/rooms?${params.toString()}`);
  }

  const field = "flex flex-col gap-1 text-left";
  const label = "text-xs font-semibold uppercase tracking-wider text-muted";
  const input =
    "h-11 rounded-lg border border-border bg-surface px-3 text-sm font-medium text-foreground focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30";

  return (
    <form
      onSubmit={submit}
      noValidate
      aria-label="Search rooms"
      className={cn(
        "grid gap-3",
        variant === "card"
          ? "rounded-2xl border border-border bg-surface p-4 shadow-sm sm:grid-cols-[1fr_1fr_110px_110px_auto] sm:items-end"
          : "sm:grid-cols-[1fr_1fr_100px_100px_auto] sm:items-end",
        className,
      )}
    >
      <label className={field}>
        <span className={label}>Check-in</span>
        <input
          type="date"
          className={input}
          value={checkIn}
          min={today}
          onChange={(e) => {
            setCheckIn(e.target.value);
            if (e.target.value && checkOut <= e.target.value) setCheckOut(addDays(e.target.value, 1));
          }}
          required
        />
      </label>
      <label className={field}>
        <span className={label}>Check-out</span>
        <input type="date" className={input} value={checkOut} min={checkIn ? addDays(checkIn, 1) : today} onChange={(e) => setCheckOut(e.target.value)} required />
      </label>
      <label className={field}>
        <span className={label}>Adults</span>
        <select className={input} value={adults} onChange={(e) => setAdults(Number(e.target.value))}>
          {Array.from({ length: 10 }, (_, i) => i + 1).map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </select>
      </label>
      <label className={field}>
        <span className={label}>Children</span>
        <select className={input} value={children} onChange={(e) => setChildren(Number(e.target.value))}>
          {Array.from({ length: 7 }, (_, i) => i).map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </select>
      </label>
      <Button type="submit" size="lg" className="h-11">
        Check availability
      </Button>
      {error && (
        <p role="alert" className="text-sm font-medium text-danger sm:col-span-5">
          {error}
        </p>
      )}
    </form>
  );
}
