import type { Metadata } from "next";
import { Suspense } from "react";
import { LoadingState } from "@/components/ui/states";
import { AuditLogsPage } from "@/features/audit/audit-logs-page";

export const metadata: Metadata = { title: "Audit log" };

export default function Page() {
  return (
    <Suspense fallback={<LoadingState />}>
      <AuditLogsPage />
    </Suspense>
  );
}
