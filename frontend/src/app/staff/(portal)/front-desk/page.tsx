import type { Metadata } from "next";
import { FrontDeskPage } from "@/features/hotel/front-desk-page";

export const metadata: Metadata = { title: "Front desk" };

export default function Page() {
  return <FrontDeskPage />;
}
