import type { Metadata } from "next";
import { PropertySettingsPage } from "@/features/hotel/property-settings";

export const metadata: Metadata = { title: "Property & policies" };

export default function Page() {
  return <PropertySettingsPage />;
}
