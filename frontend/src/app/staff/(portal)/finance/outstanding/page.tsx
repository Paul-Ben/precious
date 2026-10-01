import type { Metadata } from "next";
import { OutstandingPage } from "@/features/finance/outstanding-page";

export const metadata: Metadata = { title: "Outstanding bills" };

export default function Page() {
  return <OutstandingPage />;
}
