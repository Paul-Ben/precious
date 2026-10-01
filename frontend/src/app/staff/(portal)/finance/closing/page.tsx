import type { Metadata } from "next";
import { ClosingPage } from "@/features/finance/closing-page";

export const metadata: Metadata = { title: "Daily closing" };

export default async function Page({ searchParams }: PageProps<"/staff/finance/closing">) {
  const { date } = await searchParams;
  return <ClosingPage initialDate={typeof date === "string" ? date : undefined} />;
}
