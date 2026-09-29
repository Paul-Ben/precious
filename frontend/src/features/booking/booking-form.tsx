"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { formatStayDate } from "@/lib/dates";
import { formatNaira, formatPercent } from "@/lib/money";
import { emailSchema } from "@/lib/validation/auth";
import { bookingApi, bookingKeys, bookingTokenStore, type BasketLine, type StaySearch } from "./api";

const schema = z.object({
  first_name: z.string().trim().min(1, "Enter your first name.").max(80),
  last_name: z.string().trim().min(1, "Enter your last name.").max(80),
  email: emailSchema,
  phone: z
    .string()
    .trim()
    .regex(/^\+?[0-9]{7,15}$/, "Enter a valid phone number, e.g. +2348012345678."),
  special_requests: z.string().max(1000).optional(),
  accept_terms: z.boolean().refine((v) => v, "Please accept the booking terms."),
});
type Values = z.infer<typeof schema>;

export function BookingForm({ search, lines, prefill }: { search: StaySearch; lines: BasketLine[]; prefill?: Partial<Values> }) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);

  const quote = useQuery({
    queryKey: bookingKeys.quote(search, lines),
    queryFn: () => bookingApi.quote(search, lines),
    retry: false,
  });
  const property = useQuery({ queryKey: bookingKeys.property, queryFn: bookingApi.property, staleTime: 5 * 60_000 });

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { first_name: "", last_name: "", email: "", phone: "", special_requests: "", accept_terms: false, ...prefill },
  });
  const { register, handleSubmit, setError, formState } = form;

  const book = useMutation({
    mutationFn: (values: Values) =>
      bookingApi.book({
        ...search,
        rooms: lines,
        guest: { first_name: values.first_name, last_name: values.last_name, email: values.email, phone: values.phone },
        special_requests: values.special_requests || undefined,
        accept_terms: values.accept_terms,
      }),
    onSuccess: (res) => {
      const { reservation, lookup_token } = res.data;
      bookingTokenStore.save(reservation.number, lookup_token);
      router.push(`/book/confirmation/${encodeURIComponent(reservation.number)}`);
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        const map: [string, keyof Values][] = [
          ["guest.first_name", "first_name"],
          ["guest.last_name", "last_name"],
          ["guest.email", "email"],
          ["guest.phone", "phone"],
          ["special_requests", "special_requests"],
          ["accept_terms", "accept_terms"],
        ];
        let applied = false;
        for (const [apiField, field] of map) {
          const message = error.field(apiField);
          if (message) {
            setError(field, { type: "server", message });
            applied = true;
          }
        }
        if (applied) return;
      }
      setFormError(errorMessage(error));
    },
  });

  if (quote.isPending) return <LoadingState label="Preparing your booking…" />;
  if (quote.isError) {
    const code = isApiError(quote.error) ? quote.error.code : null;
    return (
      <Card className="mx-auto max-w-xl">
        {code === "OCCUPANCY_EXCEEDED" || code === "INVALID_DATES" || code === "INVALID_ROOMS" ? (
          <CardBody className="space-y-3">
            <Alert tone="warning">{errorMessage(quote.error)}</Alert>
            <Link href="/rooms" className="text-sm font-medium underline underline-offset-4">
              Change your search
            </Link>
          </CardBody>
        ) : (
          <ErrorState error={quote.error} onRetry={() => quote.refetch()} />
        )}
      </Card>
    );
  }

  const q = quote.data;
  const policies = property.data?.policies;

  return (
    <div className="grid gap-8 lg:grid-cols-[1fr_380px]">
      <form
        noValidate
        onSubmit={handleSubmit((v) => {
          setFormError(null);
          book.mutate(v);
        })}
        className="space-y-6"
      >
        {!q.available && (
          <Alert tone="danger" title="No longer available">
            Some of these rooms have just been booked. <Link href={`/rooms?${new URLSearchParams(Object.entries(search).map(([k, v]) => [k, String(v)])).toString()}`} className="underline">Choose again</Link>.
          </Alert>
        )}
        {formError && <Alert tone="danger">{formError}</Alert>}

        <Card>
          <CardHeader title="Guest details" description="We'll send your confirmation and receipt here." />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <Field label="First name" error={formState.errors.first_name?.message} required>
              <Input autoComplete="given-name" {...register("first_name")} />
            </Field>
            <Field label="Last name" error={formState.errors.last_name?.message} required>
              <Input autoComplete="family-name" {...register("last_name")} />
            </Field>
            <Field label="Email" error={formState.errors.email?.message} required>
              <Input type="email" autoComplete="email" inputMode="email" {...register("email")} />
            </Field>
            <Field label="Phone" error={formState.errors.phone?.message} required>
              <Input type="tel" autoComplete="tel" inputMode="tel" placeholder="+234…" {...register("phone")} />
            </Field>
            <Field label="Special requests" className="sm:col-span-2" hint="Optional — e.g. late arrival, airport pickup.">
              <textarea
                rows={3}
                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30"
                {...register("special_requests")}
              />
            </Field>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="space-y-3 text-sm">
            <p className="text-muted">
              Your rooms are held for {policies?.hold_minutes ?? 30} minutes after you book. A {formatPercent(q.deposit_percent)} deposit (
              {formatNaira(q.deposit)}) confirms the reservation; the balance is paid at check-out. Free cancellation up to{" "}
              {policies?.free_cancellation_hours ?? 48} hours before check-in ({policies?.check_in_time ?? "14:00"}).
            </p>
            <Checkbox label="I accept the booking and cancellation terms" {...register("accept_terms")} />
            {formState.errors.accept_terms && (
              <p role="alert" className="text-xs font-medium text-danger">
                {formState.errors.accept_terms.message}
              </p>
            )}
          </CardBody>
        </Card>

        <Button type="submit" size="lg" className="w-full sm:w-auto" loading={book.isPending} disabled={!q.available}>
          Reserve and continue to payment
        </Button>
      </form>

      <aside aria-label="Booking summary">
        <Card className="lg:sticky lg:top-6">
          <CardHeader title="Your stay" description={`${formatStayDate(search.check_in, true)} → ${formatStayDate(search.check_out, true)}`} />
          <CardBody className="space-y-4 text-sm">
            <ul className="space-y-2">
              {q.lines.map((line) => (
                <li key={line.room_type_id} className="flex justify-between gap-3">
                  <span>
                    {line.quantity} × {line.room_type}
                    <span className="block text-xs text-muted">
                      {formatNaira(line.nightly_rate)} × {line.nights} {line.nights === 1 ? "night" : "nights"}
                    </span>
                  </span>
                  <span className="font-medium">{formatNaira(line.subtotal)}</span>
                </li>
              ))}
            </ul>
            <dl className="space-y-1.5 border-t border-border pt-3">
              {q.service_charge !== "0.00" && (
                <div className="flex justify-between">
                  <dt className="text-muted">Service charge ({formatPercent(q.service_charge_percent)})</dt>
                  <dd>{formatNaira(q.service_charge)}</dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt className="text-muted">VAT ({formatPercent(q.vat_percent)})</dt>
                <dd>{formatNaira(q.vat)}</dd>
              </div>
              <div className="flex justify-between text-base font-bold">
                <dt>Total</dt>
                <dd>{formatNaira(q.total)}</dd>
              </div>
              <div className="flex justify-between text-accent-foreground">
                <dt>Deposit to confirm ({formatPercent(q.deposit_percent)})</dt>
                <dd className="font-semibold">{formatNaira(q.deposit)}</dd>
              </div>
              <div className="flex justify-between text-muted">
                <dt>Due at check-out</dt>
                <dd>{formatNaira(q.balance_after_deposit)}</dd>
              </div>
            </dl>
            <p className="text-xs text-muted">
              {search.adults} {search.adults === 1 ? "adult" : "adults"}
              {search.children ? `, ${search.children} ${search.children === 1 ? "child" : "children"}` : ""}. Prices are calculated by the hotel and fixed once you book.
            </p>
          </CardBody>
        </Card>
      </aside>
    </div>
  );
}
