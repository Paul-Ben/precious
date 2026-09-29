import type { Metadata } from "next";
import { BarSetupPage } from "@/features/bar/setup-page";

export const metadata: Metadata = { title: "Bar setup" };

export default function Page() {
  return <BarSetupPage />;
}
