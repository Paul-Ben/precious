import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { BookingForm } from "@/features/booking/booking-form";
import type { User } from "@/lib/api/types";
import { isValidDate } from "@/lib/dates";
import { getCurrentUser } from "@/lib/server/api";

export const metadata: Metadata = { title: "Complete your booking", robots: { index: false } };

function num(value: string | string[] | undefined, min: number, max: number): number | null {
  const n = Number(Array.isArray(value) ? value[0] : value);
  return Number.isInteger(n) && n >= min && n <= max ? n : null;
}

export default async function BookPage({ searchParams }: PageProps<"/book">) {
  const p = await searchParams;
  const checkIn = typeof p.check_in === "string" && isValidDate(p.check_in) ? p.check_in : null;
  const checkOut = typeof p.check_out === "string" && isValidDate(p.check_out) ? p.check_out : null;
  const roomTypeId = num(p.room_type_id, 1, Number.MAX_SAFE_INTEGER);
  const quantity = num(p.quantity, 1, 10) ?? 1;
  const adults = num(p.adults, 1, 20) ?? 2;
  const children = num(p.children, 0, 20) ?? 0;

  if (!checkIn || !checkOut || !roomTypeId) redirect("/rooms");

  const session = await getCurrentUser();
  const user: User | null = session.state === "authenticated" && session.user.type === "customer" ? session.user : null;
  const [first = "", ...rest] = (user?.name ?? "").split(" ");

  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6">
      <h1 className="font-[family-name:var(--font-display)] text-3xl tracking-tight sm:text-4xl">Almost there</h1>
      <BookingForm
        search={{ check_in: checkIn, check_out: checkOut, adults, children }}
        lines={[{ room_type_id: roomTypeId, quantity }]}
        prefill={user ? { first_name: first, last_name: rest.join(" "), email: user.email, phone: user.phone ?? "" } : undefined}
      />
    </div>
  );
}
