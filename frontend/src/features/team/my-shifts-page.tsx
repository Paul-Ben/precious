"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Clock, LogIn, LogOut, MapPin, Users } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardBody } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { errorMessage } from "@/lib/api/errors";
import { formatStayDate, todayInHotel } from "@/lib/dates";
import { cn } from "@/lib/utils";
import { type Shift, attendanceBadge, hotelTime, teamApi, teamKeys } from "./api";
import { useNow } from "./use-now";

/** P25 / P28: your shifts, clock in and out, and who you are working with. Every staff member sees this. */
export function MyShiftsPage() {
  const q = useQuery({ queryKey: teamKeys.myShifts, queryFn: teamApi.myShifts, refetchInterval: 60_000 });
  const today = todayInHotel();

  // Overnight shifts that started yesterday stay here while they can still be clocked.
  const upcoming = (q.data ?? []).filter((s) => s.date >= today || s.can_clock_in || s.can_clock_out);
  const past = (q.data ?? []).filter((s) => !upcoming.includes(s)).reverse();

  return (
    <>
      <PageHeader title="My shifts" description="Clock in from 30 minutes before your shift starts. Clock out when you finish." />
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (q.data ?? []).length === 0 ? (
        <EmptyState title="No shifts">You have no shifts in the past week or the next four weeks.</EmptyState>
      ) : (
        <div className="space-y-6">
          <section aria-labelledby="upcoming" className="space-y-3">
            <h2 id="upcoming" className="text-sm font-semibold uppercase tracking-wider text-muted">Coming up</h2>
            {upcoming.length === 0 ? (
              <p className="text-sm text-muted">Nothing scheduled.</p>
            ) : (
              upcoming.map((s) => <ShiftCard key={s.id} shift={s} today={today} />)
            )}
          </section>
          {past.length > 0 && (
            <section aria-labelledby="past" className="space-y-3">
              <h2 id="past" className="text-sm font-semibold uppercase tracking-wider text-muted">Last 7 days</h2>
              {past.map((s) => (
                <ShiftCard key={s.id} shift={s} today={today} compact />
              ))}
            </section>
          )}
        </div>
      )}
    </>
  );
}

function ShiftCard({ shift, today, compact = false }: { shift: Shift; today: string; compact?: boolean }) {
  const queryClient = useQueryClient();
  const [message, setMessage] = useState<string | null>(null);
  const cancelled = shift.status === "CANCELLED";
  const now = useNow();
  const badge = attendanceBadge(shift, now);
  const a = shift.attendance;

  const clock = useMutation({
    mutationFn: (direction: "in" | "out") => (direction === "in" ? teamApi.clockIn(shift.id) : teamApi.clockOut(shift.id)),
    onMutate: () => setMessage(null),
    onSuccess: async (res) => {
      setMessage(res.message);
      // Also refreshes the rota and attendance views (managers clock in too).
      await queryClient.invalidateQueries({ queryKey: ["team"] });
    },
  });

  return (
    <Card className={cn(cancelled && "opacity-60", shift.can_clock_out && "ring-2 ring-info/40")}>
      <CardBody className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1 space-y-1">
          <p className="font-semibold">
            {shift.date === today ? "Today" : formatStayDate(shift.date)} · {shift.start_time}–{shift.end_time}
            {shift.overnight && <span className="font-normal text-muted"> (ends next day)</span>}
          </p>
          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
            {shift.template && (
              <span className="inline-flex items-center gap-1"><Clock className="size-3.5" aria-hidden /> {shift.template.name}</span>
            )}
            {(shift.department || shift.location) && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3.5" aria-hidden /> {[shift.department?.name, shift.location].filter(Boolean).join(" · ")}
              </span>
            )}
          </p>
          {shift.notes && <p className="text-sm">{shift.notes}</p>}
          {cancelled ? (
            <Badge tone="danger">Cancelled{shift.cancel_reason ? ` — ${shift.cancel_reason}` : ""}</Badge>
          ) : (
            (a || Date.parse(shift.starts_at) <= now) && (
              <p className="flex flex-wrap items-center gap-2 text-sm">
                <Badge tone={badge.tone}>{badge.label}</Badge>
                {a?.clock_in_at && <span className="text-muted">In {hotelTime(a.clock_in_at)}</span>}
                {a?.clock_out_at && <span className="text-muted">Out {hotelTime(a.clock_out_at)}</span>}
              </p>
            )
          )}
          {!compact && !cancelled && (shift.colleagues?.length ?? 0) > 0 && (
            <div className="pt-1 text-sm">
              <p className="inline-flex items-center gap-1 text-muted"><Users className="size-3.5" aria-hidden /> Working with you</p>
              <ul className="mt-1 space-y-0.5">
                {shift.colleagues!.map((c, i) => (
                  <li key={i}>
                    {c.name}
                    <span className="text-muted">
                      {" "}· {c.start_time}–{c.end_time}
                      {(c.department || c.location) && ` · ${[c.department, c.location].filter(Boolean).join(" · ")}`}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        {!cancelled && (shift.can_clock_in || shift.can_clock_out) && (
          <div className="w-full sm:w-auto">
            {shift.can_clock_in ? (
              <Button size="lg" className="w-full" loading={clock.isPending} onClick={() => clock.mutate("in")}>
                <LogIn className="size-5" aria-hidden /> Clock in
              </Button>
            ) : (
              <Button size="lg" variant="outline" className="w-full" loading={clock.isPending} onClick={() => clock.mutate("out")}>
                <LogOut className="size-5" aria-hidden /> Clock out
              </Button>
            )}
          </div>
        )}
        {message && <Alert tone="success" className="w-full">{message}</Alert>}
        {clock.isError && <Alert tone="danger" className="w-full">{errorMessage(clock.error)}</Alert>}
      </CardBody>
    </Card>
  );
}
