import type { Metadata } from "next";
import { RoomResults } from "@/features/booking/room-results";
import { addDays, isValidDate, todayInHotel } from "@/lib/dates";

export const metadata: Metadata = {
  title: "Rooms & availability",
  description: "Check room availability and prices for your dates.",
};

function int(value: string | string[] | undefined, fallback: number, min: number, max: number): number {
  const n = Number(Array.isArray(value) ? value[0] : value);
  return Number.isInteger(n) && n >= min && n <= max ? n : fallback;
}

export default async function RoomsPage({ searchParams }: PageProps<"/rooms">) {
  const params = await searchParams;
  const today = todayInHotel();
  const checkIn = typeof params.check_in === "string" && isValidDate(params.check_in) ? params.check_in : addDays(today, 1);
  const checkOut =
    typeof params.check_out === "string" && isValidDate(params.check_out) && params.check_out > checkIn ? params.check_out : addDays(checkIn, 1);

  return (
    <RoomResults
      search={{
        check_in: checkIn,
        check_out: checkOut,
        adults: int(params.adults, 2, 1, 20),
        children: int(params.children, 0, 0, 20),
      }}
    />
  );
}
