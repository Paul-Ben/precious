import type { Metadata } from "next";
import { PaymentDetail } from "@/features/payments/payment-detail";

export const metadata: Metadata = { title: "Payment" };

export default async function Page({ params }: PageProps<"/staff/payments/[id]">) {
  const { id } = await params;
  return <PaymentDetail id={id} />;
}
