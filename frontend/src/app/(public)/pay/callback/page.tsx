import type { Metadata } from "next";
import { Suspense } from "react";
import { LoadingState } from "@/components/ui/states";
import { PaymentCallback } from "@/features/payments/payment-callback";

export const metadata: Metadata = { title: "Payment", robots: { index: false } };

export default function Page() {
  return (
    <div className="mx-auto max-w-xl px-4 py-12">
      <Suspense fallback={<LoadingState />}>
        <PaymentCallback />
      </Suspense>
    </div>
  );
}
