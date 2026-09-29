"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { HotelPolicies, Property } from "@/lib/api/types";
import { hotelApi, hotelKeys } from "./api";

export function PropertySettingsPage() {
  return (
    <RequirePermission permission={["settings.view", "settings.update"]}>
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const property = useQuery({ queryKey: hotelKeys.property, queryFn: hotelApi.property });
  return (
    <>
      <PageHeader title="Property & policies" description="Hotel details and the rules applied to new bookings. Existing bookings keep the rules they were made under." />
      {property.isPending ? <LoadingState /> : property.isError ? <ErrorState error={property.error} onRetry={() => property.refetch()} /> : <Form key={property.dataUpdatedAt} property={property.data} />}
    </>
  );
}

type Policies = HotelPolicies & Record<string, unknown>;

function Form({ property }: { property: Property }) {
  const { can } = useSession();
  const editable = can("settings.update");
  const queryClient = useQueryClient();
  const [details, setDetails] = useState({
    name: property.name,
    legal_name: property.legal_name ?? "",
    email: property.email ?? "",
    phone: property.phone ?? "",
    address: property.address ?? "",
    city: property.city ?? "",
    state: property.state ?? "",
  });
  const [policies, setPolicies] = useState<Policies>({ ...(property.policies as Policies) });

  const save = useMutation({
    mutationFn: () =>
      hotelApi.updateProperty({
        property: Object.fromEntries(Object.entries(details).map(([k, v]) => [k, v === "" && k !== "name" ? null : v])) as Partial<Property>,
        policies: {
          ...policies,
          deposit_percent: String(policies.deposit_percent),
          vat_percent: String(policies.vat_percent),
          service_charge_percent: String(policies.service_charge_percent ?? "0"),
          accommodation_service_charge_percent: String(policies.accommodation_service_charge_percent ?? "0"),
        },
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKeys.property }),
  });
  const err = isApiError(save.error) ? save.error : null;
  const pol = (k: keyof HotelPolicies, v: string | number | boolean) => setPolicies((p) => ({ ...p, [k]: v }));
  const num = (k: keyof HotelPolicies) => ({
    type: "number",
    value: String(policies[k] ?? ""),
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => pol(k, Number(e.target.value)),
  });
  const text = (k: keyof HotelPolicies) => ({
    value: String(policies[k] ?? ""),
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => pol(k, e.target.value),
  });

  return (
    <div className="space-y-6">
      {save.isSuccess && <Alert tone="success">{save.data.message}</Alert>}
      {save.isError && <Alert tone="danger">{err?.isValidation ? Object.values(err.errors).flat().join(" ") : errorMessage(save.error)}</Alert>}

      <fieldset disabled={!editable} className="space-y-6">
        <Card>
          <CardHeader title="Hotel details" />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <Field label="Hotel name"><Input value={details.name} onChange={(e) => setDetails((d) => ({ ...d, name: e.target.value }))} /></Field>
            <Field label="Legal name"><Input value={details.legal_name} onChange={(e) => setDetails((d) => ({ ...d, legal_name: e.target.value }))} /></Field>
            <Field label="Email"><Input type="email" value={details.email} onChange={(e) => setDetails((d) => ({ ...d, email: e.target.value }))} /></Field>
            <Field label="Phone"><Input type="tel" value={details.phone} onChange={(e) => setDetails((d) => ({ ...d, phone: e.target.value }))} /></Field>
            <Field label="Address" className="sm:col-span-2"><Input value={details.address} onChange={(e) => setDetails((d) => ({ ...d, address: e.target.value }))} /></Field>
            <Field label="City"><Input value={details.city} onChange={(e) => setDetails((d) => ({ ...d, city: e.target.value }))} /></Field>
            <Field label="State"><Input value={details.state} onChange={(e) => setDetails((d) => ({ ...d, state: e.target.value }))} /></Field>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Booking & payment" />
          <CardBody className="grid gap-4 sm:grid-cols-3">
            <Field label="Deposit to confirm (%)"><Input inputMode="decimal" {...text("deposit_percent")} /></Field>
            <Field label="Online hold (minutes)" hint="Unpaid online bookings expire after this."><Input {...num("hold_minutes")} /></Field>
            <Field label="Longest front-desk hold (minutes)"><Input {...num("staff_hold_max_minutes")} /></Field>
            <Field label="Free cancellation (hours before check-in)"><Input {...num("free_cancellation_hours")} /></Field>
            <Field label="Max nights per booking"><Input {...num("max_nights")} /></Field>
            <Field label="Max rooms per booking"><Input {...num("max_rooms_per_booking")} /></Field>
            <Field label="Book up to (days ahead)"><Input {...num("booking_window_days")} /></Field>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Tax & charges" />
          <CardBody className="grid gap-4 sm:grid-cols-3">
            <Field label="VAT (%)"><Input inputMode="decimal" {...text("vat_percent")} /></Field>
            <Field label="Service charge on bar & room service (%)"><Input inputMode="decimal" {...text("service_charge_percent")} /></Field>
            <Field label="Service charge on rooms (%)"><Input inputMode="decimal" {...text("accommodation_service_charge_percent")} /></Field>
            <Checkbox label="Charge VAT on accommodation" checked={!!policies.vat_on_accommodation} onChange={(e) => pol("vat_on_accommodation", e.target.checked)} />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Payments, refunds & check-in" />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <Checkbox
              label="Payer covers online payment fees"
              description="Adds the Paystack/Flutterwave processing fee to online payments so the hotel receives the full amount. Fees are not refunded."
              checked={!!policies.pass_gateway_fees_to_customer}
              onChange={(e) => pol("pass_gateway_fees_to_customer", e.target.checked)}
            />
            <Checkbox
              label="Require an ID before check-in"
              description="The guest's ID must be on their profile or recorded at the desk."
              checked={!!policies.require_id_at_check_in}
              onChange={(e) => pol("require_id_at_check_in", e.target.checked)}
            />
            <Field label="Refunds above this need a second approver (₦)">
              <Input inputMode="decimal" {...text("refund_second_approval_above")} />
            </Field>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Times" />
          <CardBody className="grid gap-4 sm:grid-cols-4">
            <Field label="Check-in from"><Input type="time" {...text("check_in_time")} /></Field>
            <Field label="Check-out by"><Input type="time" {...text("check_out_time")} /></Field>
            <Field label="Half-rate late checkout until"><Input type="time" {...text("late_checkout_half_rate_until")} /></Field>
            <Field label="No-show marked at"><Input type="time" {...text("no_show_time")} /></Field>
          </CardBody>
        </Card>
      </fieldset>

      {editable && <Button size="lg" loading={save.isPending} onClick={() => save.mutate()}>Save settings</Button>}
    </div>
  );
}
