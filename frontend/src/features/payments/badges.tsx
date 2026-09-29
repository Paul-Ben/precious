import { Badge } from "@/components/ui/badge";
import type { RefundStatus, TransactionStatus } from "@/lib/api/types";

const tx: Record<TransactionStatus, [string, "neutral" | "success" | "warning" | "danger" | "info"]> = {
  PENDING: ["Pending", "warning"],
  SUCCESSFUL: ["Successful", "success"],
  FAILED: ["Failed", "danger"],
  ABANDONED: ["Abandoned", "neutral"],
};

const refund: Record<RefundStatus, [string, "neutral" | "success" | "warning" | "danger" | "info"]> = {
  REQUESTED: ["Awaiting approval", "warning"],
  APPROVED: ["Approved - to pay out", "info"],
  REJECTED: ["Rejected", "neutral"],
  COMPLETED: ["Refunded", "success"],
};

export function TransactionStatusBadge({ status }: { status: TransactionStatus }) {
  const [label, tone] = tx[status] ?? [status, "neutral"];
  return <Badge tone={tone}>{label}</Badge>;
}

export function RefundStatusBadge({ status }: { status: RefundStatus }) {
  const [label, tone] = refund[status] ?? [status, "neutral"];
  return <Badge tone={tone}>{label}</Badge>;
}
