"use client";

import { useQuery } from "@tanstack/react-query";
import { Users } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { formatStayDate } from "@/lib/dates";
import { formatNaira, formatPercent } from "@/lib/money";
import { bookingApi, bookingKeys, type StaySearch } from "./api";
import { RoomImage } from "./room-image";
import { SearchForm } from "./search-form";

export function RoomResults({ search }: { search: StaySearch }) {
  const results = useQuery({
    queryKey: bookingKeys.availability(search),
    queryFn: ({ signal }) => bookingApi.availability(search, signal),
  });
  const [quantities, setQuantities] = useState<Record<number, number>>({});

  return (
    <div className="mx-auto max-w-6xl space-y-8 px-4 py-10 sm:px-6">
      <SearchForm defaults={search} />

      {results.isPending ? (
        <LoadingState label="Checking availability…" />
      ) : results.isError ? (
        <Card>
          <ErrorState error={results.error} onRetry={() => results.refetch()} />
        </Card>
      ) : results.data.room_types.length === 0 ? (
        <Card>
          <EmptyState title="No rooms are set up yet">Please check back soon or contact the hotel.</EmptyState>
        </Card>
      ) : (
        <>
          <div>
            <h1 className="font-[family-name:var(--font-display)] text-3xl tracking-tight sm:text-4xl">Available rooms</h1>
            <p className="mt-1 text-sm text-muted">
              {formatStayDate(results.data.check_in)} → {formatStayDate(results.data.check_out)} · {results.data.nights}{" "}
              {results.data.nights === 1 ? "night" : "nights"} · {search.adults} {search.adults === 1 ? "adult" : "adults"}
              {search.children > 0 ? ` · ${search.children} ${search.children === 1 ? "child" : "children"}` : ""}
            </p>
          </div>

          <ul className="space-y-4">
            {results.data.room_types.map((type) => {
              const soldOut = type.available_count === 0;
              const qty = Math.min(quantities[type.id] ?? 1, Math.max(type.available_count, 1));
              const params = new URLSearchParams({
                check_in: search.check_in,
                check_out: search.check_out,
                adults: String(search.adults),
                children: String(search.children),
                room_type_id: String(type.id),
                quantity: String(qty),
              });

              return (
                <li key={type.id}>
                  <Card className={soldOut ? "opacity-70" : undefined}>
                    <div className="grid gap-4 p-4 md:grid-cols-[260px_1fr_220px]">
                      <div className="h-44 overflow-hidden rounded-xl">
                        <RoomImage url={type.cover_image_url} alt={type.name} />
                      </div>
                      <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <h2 className="font-[family-name:var(--font-display)] text-2xl">
                            <Link href={`/rooms/${type.slug}`} className="hover:underline">
                              {type.name}
                            </Link>
                          </h2>
                          {!soldOut && type.available_count <= 2 && <Badge tone="warning">Only {type.available_count} left</Badge>}
                          {soldOut && <Badge>Fully booked</Badge>}
                        </div>
                        {type.short_description && <p className="text-sm text-muted">{type.short_description}</p>}
                        <p className="flex items-center gap-1.5 text-sm text-muted">
                          <Users className="size-4" aria-hidden="true" /> Up to {type.max_adults} adults · sleeps {type.max_occupancy}
                          {type.bed_type ? ` · ${type.bed_type}` : ""}
                        </p>
                        {!!type.amenities?.length && (
                          <ul className="flex flex-wrap gap-1.5" aria-label="Amenities">
                            {type.amenities.slice(0, 6).map((a) => (
                              <li key={a.id}>
                                <Badge>{a.name}</Badge>
                              </li>
                            ))}
                          </ul>
                        )}
                        {!type.fits_party_in_one_room && !soldOut && (
                          <p className="text-xs font-medium text-warning">Your party needs more than one of these rooms.</p>
                        )}
                      </div>
                      <div className="flex flex-col items-start justify-between gap-3 md:items-end md:text-right">
                        <div>
                          <p className="text-2xl font-bold">{formatNaira(type.base_rate)}</p>
                          <p className="text-xs text-muted">per night</p>
                          <p className="mt-1 text-sm">
                            {formatNaira(type.quote.total)} for {type.quote.nights} {type.quote.nights === 1 ? "night" : "nights"}
                          </p>
                          <p className="text-xs text-muted">incl. {formatPercent(type.quote.vat_percent)} VAT</p>
                        </div>
                        {!soldOut && (
                          <div className="flex items-center gap-2">
                            <label className="sr-only" htmlFor={`qty-${type.id}`}>
                              Number of {type.name} rooms
                            </label>
                            <select
                              id={`qty-${type.id}`}
                              value={qty}
                              onChange={(e) => setQuantities((q) => ({ ...q, [type.id]: Number(e.target.value) }))}
                              className="h-11 rounded-lg border border-border bg-surface px-2 text-sm"
                            >
                              {Array.from({ length: Math.min(type.available_count, 5) }, (_, i) => i + 1).map((n) => (
                                <option key={n} value={n}>
                                  {n} {n === 1 ? "room" : "rooms"}
                                </option>
                              ))}
                            </select>
                            <Link
                              href={`/book?${params.toString()}`}
                              className="inline-flex h-11 items-center rounded-lg bg-brand px-5 text-sm font-semibold text-brand-foreground hover:opacity-90"
                            >
                              Select
                            </Link>
                          </div>
                        )}
                      </div>
                    </div>
                  </Card>
                </li>
              );
            })}
          </ul>
          <p className="text-xs text-muted">Prices are fixed when you book. Selecting a room does not hold it until you complete the booking.</p>
        </>
      )}
    </div>
  );
}
