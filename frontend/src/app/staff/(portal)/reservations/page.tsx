import type { Metadata } from "next";
import { ReservationsPage } from "@/features/hotel/reservations-page";

export const metadata: Metadata = { title: "Reservations" };

export default function Page() {
  return <ReservationsPage />;
}
