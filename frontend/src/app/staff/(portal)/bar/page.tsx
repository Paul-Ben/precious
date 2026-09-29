import type { Metadata } from "next";
import { PosPage } from "@/features/bar/pos-page";

export const metadata: Metadata = { title: "Bar" };

export default function Page() {
  return <PosPage />;
}
