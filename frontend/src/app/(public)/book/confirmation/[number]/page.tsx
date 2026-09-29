import type { Metadata } from "next";
import { BookingConfirmation } from "@/features/booking/booking-confirmation";

export const metadata: Metadata = { title: "Reservation", robots: { index: false } };

export default async function ConfirmationPage({ params }: PageProps<"/book/confirmation/[number]">) {
  const { number } = await params;

  return (
    <div className="mx-auto max-w-2xl px-4 py-12 sm:px-6">
      <BookingConfirmation number={decodeURIComponent(number)} />
    </div>
  );
}
