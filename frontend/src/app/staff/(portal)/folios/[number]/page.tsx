import type { Metadata } from "next";
import { FolioView } from "@/features/stays/folio-view";

export const metadata: Metadata = { title: "Final bill" };

export default async function Page({ params }: PageProps<"/staff/folios/[number]">) {
  const { number } = await params;
  return <FolioView number={number} />;
}
