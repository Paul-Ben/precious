import type { Metadata } from "next";
import { NewReservationPage } from "@/features/hotel/new-reservation";

export const metadata: Metadata = { title: "New reservation" };

export default function Page() {
  return <NewReservationPage />;
}
