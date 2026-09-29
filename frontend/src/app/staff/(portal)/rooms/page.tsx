import type { Metadata } from "next";
import { RoomsPage } from "@/features/hotel/rooms-page";

export const metadata: Metadata = { title: "Rooms" };

export default function Page() {
  return <RoomsPage />;
}
