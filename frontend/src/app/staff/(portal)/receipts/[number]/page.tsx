import type { Metadata } from "next";
import { ReceiptView } from "@/features/payments/receipt-view";

export const metadata: Metadata = { title: "Receipt" };

export default async function Page({ params }: PageProps<"/staff/receipts/[number]">) {
  const { number } = await params;
  return <ReceiptView number={number} />;
}
