import type { Metadata } from "next";
import { Suspense } from "react";
import { LoadingState } from "@/components/ui/states";
import { PublicBarPay } from "@/features/bar/public-pay";

export const metadata: Metadata = { title: "Pay your bill", robots: { index: false } };

export default async function Page({ params }: PageProps<"/bar/pay/[number]">) {
  const { number } = await params;
  return (
    <div className="mx-auto max-w-xl px-4 py-10">
      <Suspense fallback={<LoadingState />}>
        <PublicBarPay number={number} />
      </Suspense>
    </div>
  );
}
