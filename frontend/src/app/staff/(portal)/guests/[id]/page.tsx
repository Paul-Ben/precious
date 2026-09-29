import type { Metadata } from "next";
import { GuestDetail } from "@/features/hotel/guest-detail";

export const metadata: Metadata = { title: "Guest" };

export default async function Page({ params }: PageProps<"/staff/guests/[id]">) {
  const { id } = await params;
  return <GuestDetail id={id} />;
}
