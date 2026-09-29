import type { Metadata } from "next";
import { type DeskAction, ReservationDetail } from "@/features/hotel/reservation-detail";

export const metadata: Metadata = { title: "Reservation" };

export default async function Page({ params, searchParams }: PageProps<"/staff/reservations/[id]">) {
  const [{ id }, query] = await Promise.all([params, searchParams]);
  const action: DeskAction = query.action === "check-in" || query.action === "check-out" ? query.action : null;

  return <ReservationDetail id={id} action={action} />;
}
