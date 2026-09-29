import type { Metadata } from "next";
import { ServicesPage } from "@/features/stays/services-page";

export const metadata: Metadata = { title: "Hotel services" };

export default function Page() {
  return <ServicesPage />;
}
