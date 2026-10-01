import type { Metadata } from "next";
import { RotaPage } from "@/features/team/rota-page";

export const metadata: Metadata = { title: "Rota" };

export default function Page() {
  return <RotaPage />;
}
