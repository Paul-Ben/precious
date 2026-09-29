import { AlertTriangle, CheckCircle2, Info, XCircle } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

type Tone = "info" | "success" | "warning" | "danger";

const styles: Record<Tone, { box: string; Icon: typeof Info }> = {
  info: { box: "border-info/30 bg-info-soft text-info", Icon: Info },
  success: { box: "border-success/30 bg-success-soft text-success", Icon: CheckCircle2 },
  warning: { box: "border-warning/30 bg-warning-soft text-warning", Icon: AlertTriangle },
  danger: { box: "border-danger/30 bg-danger-soft text-danger", Icon: XCircle },
};

export function Alert({
  tone = "info",
  title,
  children,
  className,
}: {
  tone?: Tone;
  title?: ReactNode;
  children?: ReactNode;
  className?: string;
}) {
  const { box, Icon } = styles[tone];
  return (
    <div role={tone === "danger" ? "alert" : "status"} className={cn("flex gap-3 rounded-lg border px-4 py-3 text-sm", box, className)}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <div className="space-y-0.5">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className="text-foreground/90">{children}</div>}
      </div>
    </div>
  );
}
