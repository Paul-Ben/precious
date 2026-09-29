"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Card, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { ReservationStatusBadge } from "@/features/booking/status-badge";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage } from "@/lib/api/errors";
import type { BoardRoom, Reservation, RoomStatus } from "@/lib/api/types";
import { formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { hotelApi, hotelKeys, ROOM_STATUS_LABELS } from "./api";

export function FrontDeskPage() {
  return (
    <RequirePermission permission={["reservations.view", "rooms.view"]}>
      <FrontDesk />
    </RequirePermission>
  );
}

function Stat({ label, value, hint }: { label: string; value: number | string; hint?: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface p-4">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-1 text-3xl font-bold">{value}</p>
      {hint && <p className="text-xs text-muted">{hint}</p>}
    </div>
  );
}

function FrontDesk() {
  const { can } = useSession();
  const today = todayInHotel();
  const summary = useQuery({ queryKey: hotelKeys.summary(), queryFn: () => hotelApi.summary(), enabled: can("reservations.view"), refetchInterval: 60_000 });

  return (
    <>
      <PageHeader
        title="Front desk"
        description={formatStayDate(today, true)}
        actions={
          can("reservations.create") && (
            <Link href="/staff/reservations/new" className="inline-flex h-11 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-medium text-brand-foreground">
              <Plus className="size-4" aria-hidden="true" /> New reservation
            </Link>
          )
        }
      />

      {summary.data && (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Stat label="Arrivals today" value={summary.data.arrivals} hint={`${summary.data.arrivals_checked_in} checked in`} />
          <Stat label="Departures today" value={summary.data.departures} hint={`${summary.data.departures_checked_out} checked out`} />
          <Stat label="In house" value={summary.data.in_house} />
          <Stat label="Available tonight" value={summary.data.available_tonight} hint={`of ${summary.data.total_rooms} rooms`} />
          <Stat label="Awaiting payment" value={summary.data.pending_payment} />
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-2">
        {can("reservations.view") && <Movements kind="arrivals" title="Arrivals today" filter={{ arrivals_on: today, status: "PENDING_PAYMENT,CONFIRMED,CHECKED_IN" }} />}
        {can("reservations.view") && <Movements kind="departures" title="Departures today" filter={{ departures_on: today, status: "CONFIRMED,CHECKED_IN,CHECKED_OUT" }} />}
      </div>

      {can("rooms.view") && <RoomBoard />}
      <p className="mt-4 text-xs text-muted">Check-in and check-out buttons are switched on with the Payments &amp; Stays release.</p>
    </>
  );
}

function Movements({ kind, title, filter }: { kind: "arrivals" | "departures"; title: string; filter: Record<string, string> }) {
  const { can } = useSession();
  const list = useQuery({ queryKey: hotelKeys.reservations({ ...filter, per_page: 50 }), queryFn: () => hotelApi.reservations({ ...filter, per_page: 50 }) });

  return (
    <Card>
      <CardHeader title={title} />
      {list.isPending ? (
        <LoadingState />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.items.length === 0 ? (
        <EmptyState title="Nothing scheduled" />
      ) : (
        <ul className="divide-y divide-border">
          {list.data.items.map((r: Reservation) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm hover:bg-surface-muted/60">
              <Link href={`/staff/reservations/${r.id}`} className="min-w-0 flex-1">
                <p className="font-semibold">{r.guest?.full_name}</p>
                <p className="text-xs text-muted">
                  {r.number} · {r.rooms?.map((x) => x.room_number ?? x.room_type.name).join(", ")} · {r.nights}n
                </p>
              </Link>
              <span className="text-xs text-muted">
                {formatNaira(r.amount_paid)} / {formatNaira(r.grand_total ?? r.total)}
              </span>
              <ReservationStatusBadge status={r.status} />
              {kind === "arrivals" && r.status === "CONFIRMED" && can("checkins.create") && (
                <Link href={`/staff/reservations/${r.id}?action=check-in`} className="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-foreground">Check in</Link>
              )}
              {kind === "departures" && r.status === "CHECKED_IN" && can("checkouts.create") && (
                <Link href={`/staff/reservations/${r.id}?action=check-out`} className="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-foreground">Check out</Link>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

const TILE: Record<RoomStatus, string> = {
  AVAILABLE: "border-success/40 bg-success-soft",
  RESERVED: "border-info/40 bg-info-soft",
  OCCUPIED: "border-accent/60 bg-accent/15",
  DIRTY: "border-warning/50 bg-warning-soft",
  CLEANING: "border-warning/50 bg-warning-soft",
  MAINTENANCE: "border-danger/40 bg-danger-soft",
  OUT_OF_SERVICE: "border-danger/40 bg-danger-soft",
  BLOCKED: "border-border bg-surface-muted",
};

function RoomBoard() {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const board = useQuery({ queryKey: hotelKeys.board, queryFn: hotelApi.board, refetchInterval: 60_000 });
  const [error, setError] = useState<string | null>(null);
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: RoomStatus }) => hotelApi.setRoomStatus(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKeys.board }),
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Card className="mt-6">
      <CardHeader title="Room board" description="Live housekeeping status and today's guests." />
      {error && <Alert tone="danger" className="m-4">{error}</Alert>}
      {board.isPending ? (
        <LoadingState />
      ) : board.isError ? (
        <ErrorState error={board.error} onRetry={() => board.refetch()} />
      ) : board.data.length === 0 ? (
        <EmptyState title="No rooms set up yet" action={can("rooms.create") ? <Link href="/staff/rooms" className="text-sm font-semibold underline">Add rooms</Link> : undefined} />
      ) : (
        <ul className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
          {board.data.map((room: BoardRoom) => {
            const stay = room.today[0];
            return (
              <li key={room.id} className={cn("flex flex-col gap-1 rounded-xl border p-3 text-sm", TILE[room.status])}>
                <div className="flex items-baseline justify-between">
                  <span className="text-lg font-bold">{room.number}</span>
                  <span className="text-xs text-muted">{room.room_type?.name}</span>
                </div>
                {can("rooms.manage_status") ? (
                  <select
                    aria-label={`Status of room ${room.number}`}
                    value={room.status}
                    onChange={(e) => {
                      setError(null);
                      setStatus.mutate({ id: room.id, status: e.target.value as RoomStatus });
                    }}
                    className="h-9 rounded-md border border-border bg-surface px-2 text-xs font-semibold"
                  >
                    {Object.entries(ROOM_STATUS_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <span className="text-xs font-semibold">{ROOM_STATUS_LABELS[room.status]}</span>
                )}
                {stay ? (
                  <Link href={`/staff/reservations/${stay.reservation_id}`} className="truncate text-xs hover:underline">
                    {stay.arriving_today ? "Arriving: " : stay.departing_today ? "Departing: " : "In: "}
                    {stay.guest}
                  </Link>
                ) : (
                  <span className="text-xs text-muted">{room.blocks?.[0] ? `Blocked from ${formatStayDate(room.blocks[0].starts_on)}` : "No guest today"}</span>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
