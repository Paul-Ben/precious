"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { ArrowLeft, Search } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useDeferredValue, useMemo, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Select } from "@/components/ui/select";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Guest } from "@/lib/api/types";
import { addDays, formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { hotelApi, hotelKeys } from "./api";

export function NewReservationPage() {
  return (
    <RequirePermission permission="reservations.create">
      <NewReservation />
    </RequirePermission>
  );
}

function NewReservation() {
  const router = useRouter();
  const today = todayInHotel();
  const [checkIn, setCheckIn] = useState(today);
  const [checkOut, setCheckOut] = useState(addDays(today, 1));
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);
  const [qty, setQty] = useState<Record<number, number>>({});
  const [source, setSource] = useState("FRONT_DESK");
  const [holdHours, setHoldHours] = useState(1);
  const [guest, setGuest] = useState<Guest | null>(null);
  const [newGuest, setNewGuest] = useState({ first_name: "", last_name: "", phone: "", email: "" });
  const [special, setSpecial] = useState("");
  const [internal, setInternal] = useState("");

  const validDates = checkOut > checkIn && checkIn >= today;
  const availability = useQuery({
    queryKey: hotelKeys.deskAvailability(checkIn, checkOut),
    queryFn: () => hotelApi.deskAvailability(checkIn, checkOut),
    enabled: validDates,
  });

  const rooms = useMemo(() => Object.entries(qty).filter(([, n]) => n > 0).map(([id, n]) => ({ room_type_id: Number(id), quantity: n })), [qty]);

  const create = useMutation({
    mutationFn: () =>
      hotelApi.createReservation({
        source,
        check_in: checkIn,
        check_out: checkOut,
        adults,
        children,
        rooms,
        hold_minutes: holdHours * 60,
        special_requests: special || null,
        internal_notes: internal || null,
        ...(guest ? { guest_id: guest.id } : { guest: { ...newGuest, email: newGuest.email || null, phone: newGuest.phone || null } }),
      }),
    onSuccess: (res) => router.push(`/staff/reservations/${res.data.id}`),
  });

  const fieldErrors = isApiError(create.error) ? create.error.errors : {};
  const canSubmit = validDates && rooms.length > 0 && (guest || (newGuest.first_name && newGuest.last_name));

  return (
    <>
      <Link href="/staff/reservations" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" /> Reservations
      </Link>
      <PageHeader title="New reservation" description="Rooms are held until payment is recorded or the hold expires." />

      <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          <Card>
            <CardHeader title="1 · Dates and guests" />
            <CardBody className="grid gap-4 sm:grid-cols-4">
              <Field label="Check-in">
                <Input type="date" value={checkIn} min={today} onChange={(e) => { setCheckIn(e.target.value); if (checkOut <= e.target.value) setCheckOut(addDays(e.target.value, 1)); }} />
              </Field>
              <Field label="Check-out">
                <Input type="date" value={checkOut} min={addDays(checkIn, 1)} onChange={(e) => setCheckOut(e.target.value)} />
              </Field>
              <Field label="Adults">
                <Input type="number" min={1} max={20} value={adults} onChange={(e) => setAdults(Math.max(1, Number(e.target.value)))} />
              </Field>
              <Field label="Children">
                <Input type="number" min={0} max={20} value={children} onChange={(e) => setChildren(Math.max(0, Number(e.target.value)))} />
              </Field>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="2 · Rooms" description={validDates ? `${formatStayDate(checkIn)} → ${formatStayDate(checkOut)}` : "Choose valid dates first."} />
            {!validDates ? null : availability.isPending ? (
              <LoadingState />
            ) : availability.isError ? (
              <ErrorState error={availability.error} onRetry={() => availability.refetch()} />
            ) : (
              <ul className="divide-y divide-border">
                {availability.data.room_types.map((t) => (
                  <li key={t.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold">{t.name}</p>
                      <p className="text-xs text-muted">
                        {formatNaira(t.base_rate)}/night · up to {t.max_adults} adults · free: {t.available_rooms.map((r) => r.number).join(", ") || "none"}
                      </p>
                    </div>
                    <label className="flex items-center gap-2">
                      <span className="sr-only">{t.name} rooms</span>
                      <Select
                        className="h-10 w-24"
                        value={qty[t.id] ?? 0}
                        disabled={t.available_count === 0}
                        onChange={(e) => setQty((q) => ({ ...q, [t.id]: Number(e.target.value) }))}
                      >
                        {Array.from({ length: Math.min(t.available_count, 5) + 1 }, (_, i) => i).map((n) => (
                          <option key={n} value={n}>
                            {n}
                          </option>
                        ))}
                      </Select>
                    </label>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <GuestPicker selected={guest} onSelect={setGuest} newGuest={newGuest} onNewGuest={setNewGuest} errors={fieldErrors} />

          <Card>
            <CardHeader title="4 · Details" />
            <CardBody className="grid gap-4 sm:grid-cols-2">
              <Field label="Booked via">
                <Select value={source} onChange={(e) => setSource(e.target.value)}>
                  <option value="FRONT_DESK">Front desk</option>
                  <option value="PHONE">Phone</option>
                  <option value="WALK_IN">Walk-in</option>
                </Select>
              </Field>
              <Field label="Hold rooms for" hint="Unpaid reservations expire after this.">
                <Select value={holdHours} onChange={(e) => setHoldHours(Number(e.target.value))}>
                  {[1, 2, 6, 12, 24, 48, 72].map((h) => (
                    <option key={h} value={h}>
                      {h} {h === 1 ? "hour" : "hours"}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Guest requests" className="sm:col-span-2">
                <Input value={special} onChange={(e) => setSpecial(e.target.value)} maxLength={1000} />
              </Field>
              <Field label="Internal notes (staff only)" className="sm:col-span-2">
                <Input value={internal} onChange={(e) => setInternal(e.target.value)} maxLength={2000} />
              </Field>
            </CardBody>
          </Card>
        </div>

        <aside>
          <Card className="xl:sticky xl:top-20">
            <CardHeader title="Summary" />
            <CardBody className="space-y-3 text-sm">
              {rooms.length === 0 ? (
                <p className="text-muted">No rooms selected.</p>
              ) : (
                <ul className="space-y-1">
                  {rooms.map((r) => {
                    const t = availability.data?.room_types.find((x) => x.id === r.room_type_id);
                    return (
                      <li key={r.room_type_id} className="flex justify-between">
                        <span>{r.quantity} × {t?.name}</span>
                        <span className="text-muted">{t ? formatNaira(t.quote.total) : ""} each</span>
                      </li>
                    );
                  })}
                </ul>
              )}
              <p className="text-xs text-muted">Final prices (incl. VAT) and room numbers are set by the server when you create the reservation.</p>
              {create.isError && !Object.keys(fieldErrors).length && <Alert tone="danger">{errorMessage(create.error)}</Alert>}
              {!!Object.keys(fieldErrors).length && <Alert tone="danger">{Object.values(fieldErrors).flat().join(" ")}</Alert>}
              <Button className="w-full" size="lg" disabled={!canSubmit} loading={create.isPending} onClick={() => create.mutate()}>
                Create reservation
              </Button>
            </CardBody>
          </Card>
        </aside>
      </div>
    </>
  );
}

function GuestPicker({
  selected,
  onSelect,
  newGuest,
  onNewGuest,
  errors,
}: {
  selected: Guest | null;
  onSelect: (g: Guest | null) => void;
  newGuest: { first_name: string; last_name: string; phone: string; email: string };
  onNewGuest: (g: { first_name: string; last_name: string; phone: string; email: string }) => void;
  errors: Record<string, string[]>;
}) {
  const [search, setSearch] = useState("");
  const deferred = useDeferredValue(search);
  const results = useQuery({
    queryKey: hotelKeys.guests({ search: deferred, per_page: 5 }),
    queryFn: () => hotelApi.guests({ search: deferred, per_page: 5 }),
    enabled: deferred.trim().length >= 2 && !selected,
  });

  return (
    <Card>
      <CardHeader title="3 · Guest" />
      <CardBody className="space-y-4">
        {selected ? (
          <div className="flex items-center justify-between rounded-lg border border-border p-3 text-sm">
            <div>
              <p className="font-semibold">{selected.full_name}</p>
              <p className="text-xs text-muted">{[selected.phone, selected.email].filter(Boolean).join(" · ")}</p>
            </div>
            <Button variant="ghost" size="sm" onClick={() => onSelect(null)}>
              Change
            </Button>
          </div>
        ) : (
          <>
            <label className="relative block">
              <span className="sr-only">Find an existing guest</span>
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
              <Input className="pl-9" placeholder="Find existing guest by name, phone or email" value={search} onChange={(e) => setSearch(e.target.value)} />
            </label>
            {results.data && results.data.items.length > 0 && (
              <ul className="divide-y divide-border rounded-lg border border-border">
                {results.data.items.map((g) => (
                  <li key={g.id}>
                    <button type="button" className="w-full px-3 py-2 text-left text-sm hover:bg-surface-muted" onClick={() => onSelect(g)}>
                      <span className="font-medium">{g.full_name}</span>{" "}
                      <span className="text-xs text-muted">{[g.phone, g.email].filter(Boolean).join(" · ")}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <p className="text-xs font-semibold uppercase tracking-wider text-muted">…or new guest</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="First name" error={errors["guest.first_name"]?.[0]}>
                <Input value={newGuest.first_name} onChange={(e) => onNewGuest({ ...newGuest, first_name: e.target.value })} />
              </Field>
              <Field label="Last name" error={errors["guest.last_name"]?.[0]}>
                <Input value={newGuest.last_name} onChange={(e) => onNewGuest({ ...newGuest, last_name: e.target.value })} />
              </Field>
              <Field label="Phone" error={errors["guest.phone"]?.[0]}>
                <Input type="tel" value={newGuest.phone} onChange={(e) => onNewGuest({ ...newGuest, phone: e.target.value })} />
              </Field>
              <Field label="Email" error={errors["guest.email"]?.[0]}>
                <Input type="email" value={newGuest.email} onChange={(e) => onNewGuest({ ...newGuest, email: e.target.value })} />
              </Field>
            </div>
          </>
        )}
      </CardBody>
    </Card>
  );
}
