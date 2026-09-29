"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { PaymentStatusBadge, ReservationStatusBadge } from "@/features/booking/status-badge";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { formatStayDate } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { hotelApi, hotelKeys } from "./api";

const STATUS_FILTERS: { label: string; value: string }[] = [
  { label: "Active", value: "PENDING_PAYMENT,CONFIRMED,CHECKED_IN" },
  { label: "Awaiting payment", value: "PENDING_PAYMENT" },
  { label: "Confirmed", value: "CONFIRMED" },
  { label: "In house", value: "CHECKED_IN" },
  { label: "Checked out", value: "CHECKED_OUT" },
  { label: "Cancelled / expired / no-show", value: "CANCELLED,EXPIRED,NO_SHOW" },
  { label: "All", value: "" },
];

export function ReservationsPage() {
  return (
    <RequirePermission permission="reservations.view">
      <ReservationsList />
    </RequirePermission>
  );
}

function ReservationsList() {
  const { can } = useSession();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState(STATUS_FILTERS[0]!.value);
  const [from, setFrom] = useState("");
  const [page, setPage] = useState(1);
  const deferred = useDeferredValue(search);
  const filters = { search: deferred, status, from, page };

  const list = useQuery({
    queryKey: hotelKeys.reservations(filters),
    queryFn: ({ signal }) => hotelApi.reservations(filters, signal),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <PageHeader
        title="Reservations"
        actions={
          can("reservations.create") && (
            <Link href="/staff/reservations/new" className="inline-flex h-11 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-medium text-brand-foreground">
              <Plus className="size-4" aria-hidden="true" /> New reservation
            </Link>
          )
        }
      />
      <Card>
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_220px_180px]">
          <label className="relative">
            <span className="sr-only">Search reservations</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <Input
              className="pl-9"
              placeholder="Reservation no., guest, phone, email or room"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </label>
          <Select aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            {STATUS_FILTERS.map((f) => (
              <option key={f.label} value={f.value}>
                {f.label}
              </option>
            ))}
          </Select>
          <Input type="date" aria-label="Staying on or after" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
        </div>

        {list.isPending ? (
          <LoadingState />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title="No reservations match">Try another search or status.</EmptyState>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-3 font-medium">Reservation</th>
                    <th scope="col" className="px-3 py-3 font-medium">Stay</th>
                    <th scope="col" className="px-3 py-3 font-medium">Rooms</th>
                    <th scope="col" className="px-3 py-3 font-medium">Total / paid</th>
                    <th scope="col" className="px-5 py-3 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {list.data.items.map((r) => (
                    <tr key={r.id} className="hover:bg-surface-muted/60">
                      <td className="px-5 py-3">
                        <Link href={`/staff/reservations/${r.id}`} className="font-semibold hover:underline">
                          {r.guest?.full_name}
                        </Link>
                        <p className="font-mono text-xs text-muted">{r.number}</p>
                      </td>
                      <td className="px-3 py-3">
                        {formatStayDate(r.check_in)} → {formatStayDate(r.check_out)}
                        <p className="text-xs text-muted">{r.nights} nights · {r.adults + r.children} guests</p>
                      </td>
                      <td className="px-3 py-3">{r.rooms?.map((x) => x.room_number ?? x.room_type.name).join(", ")}</td>
                      <td className="px-3 py-3">
                        {formatNaira(r.total)}
                        <p className="text-xs text-muted">{formatNaira(r.amount_paid, { kobo: true })} paid</p>
                      </td>
                      <td className="space-x-1 px-5 py-3">
                        <ReservationStatusBadge status={r.status} />
                        <PaymentStatusBadge status={r.payment_status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={list.data.meta} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}
