import type { Metadata } from "next";
import { PaymentGatewaysPage } from "@/features/settings/payment-gateways";

export const metadata: Metadata = { title: "Payment gateways" };

export default function Page() {
  return <PaymentGatewaysPage />;
}
